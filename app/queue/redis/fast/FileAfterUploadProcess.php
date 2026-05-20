<?php


namespace app\queue\redis\fast;


use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Core;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\WebM;
use FFMpeg\Format\Video\X264;
use Webman\RedisQueue\Consumer;

class FileAfterUploadProcess implements Consumer
{
    public $queue = 'file-after-upload-process';
    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    public function consume($data)
    {
        if (empty($data['file_id'])) {
            return 0;
        }

        try {
            $file = $this->getAttachmentService()->getAttachmentById($data['file_id'], false);
            if (empty($file)) {
                return 0;
            }

            if (!in_array($file['type'], ['audio', 'video', 'image'])) {
                return 0;
            }


            $uploadPath = uploads_path();
            $filepath = $uploadPath . ltrim($file['filepath'], 'uploads');
            if (!is_file($filepath)) {
                return 0;
            }

            if ($file['type'] === 'image') {
                if (!empty($file['imgSize'])) {
                    return 1;
                }
                // 获取图片尺寸信息
                $imageSize = getimagesize($filepath);
                // 图片宽度
                $width = $imageSize[0];
                // 图片高度
                $height = $imageSize[1];
                $this->getAttachmentService()->updateAttachment($file['id'], [
                    'imgSize' => json_encode(['width' => $width, 'height' => $height]),
                ]);
                return 1;
            }

            if ($this->getFFmpeg() === null) {
                // 没有配置 ffmpeg
                return 0;
            }

            if ($file['length'] > 0 || !empty($file['videoCover'])) {
                return 0;
            }

            $attachmentSetting = $this->getSettingService()->get('attachment', []);
            $fileInfo = explode('.', $file['newFilename']);
            $dirPath = dirname($filepath);
            $source = $this->getFFmpeg()->open($filepath);
            $videoCover = '';
            $transcodePath = '';

            if ($file['type'] === 'video') {
                // 获取视频第一帧作为封面
                $filename = $fileInfo[0] . '_cover.jpg';
                $output = $dirPath . '/' . $filename;
                $videoCover = str_replace($uploadPath, 'uploads/', $output);
                $source->frame(TimeCode::fromSeconds(0))->save($output);
                // 获取视频时长
                $length = $source->getStreams()->videos()->first()->get('duration');
                // 格式转码
                // TODO: 增加转码状态
                if (empty($file['transcodePath']) && !empty($attachmentSetting['allow_transcode_video']) && 1 == $attachmentSetting['allow_transcode_video']) {
                    // TODO： 消耗cpu，而且会影响后面的修改数据库，应当使用命令行处理，传递文件id;
                    //                    if ($file['ext'] === 'mov') {
                    //                        $transcodeFile = $dirPath . '/' . $fileInfo[0] . '_transcode.mp4';
                    //                        $this->movToMp4($filepath, $transcodeFile);
                    //                        $transcodePath = str_replace($uploadPath, 'uploads/', $transcodeFile);
                    //                    } elseif ($file['ext'] === 'mp4') {
                    //                        $transcodeDir = $dirPath . '/' . $fileInfo[0] . '_hls/';
                    //                        $this->mp4ToHls($filepath, $transcodeDir);
                    //                        $transcodePath = str_replace($uploadPath, 'uploads/', $transcodeDir) . 'playlist.m3u8';
                    //                    }
                }
            } else {
                // 获取音频时长
                $length = $source->getStreams()->audios()->first()->get('duration');
            }

            $this->getAttachmentService()->updateAttachment($file['id'], [
                'videoCover'    => $videoCover,
                'transcodePath' => $transcodePath,
                'length'        => $length,
            ]);

            $file = null;
            return 1;
        } catch (\Throwable $e) {
            //            var_dump($e->getMessage(), $e->getTraceAsString());
            return 0;
        }
    }

    protected function movToMp4($inputFile, $outputFile)
    {
        // 打开视频文件
        $video = $this->getFFmpeg()->open($inputFile);
        // 转换视频为 mp4 格式，并保存到指定位置
        $format = new X264('aac', 'libx264');
        //        $format->setAudioCodec("libmp3lame");
        $video->save($format, $outputFile);
    }

    /**
     * mp4 视频 转 hls
     *
     * @param $inputFile
     * @param $outputDir
     */
    protected function mp4ToHls($inputFile, $outputDir)
    {
        if (!is_dir($outputDir)) {
            @mkdir($outputDir);
        }
        // 打开输入文件
        $video = $this->getFFmpeg()->open($inputFile);

        // 设置输出格式和编码器
        $format = new X264(); // 使用 X264 输出格式
        $format->setAudioCodec('aac'); // 设置音频编码器为 AAC
        //// 设置输出路径和文件名格式
        $format->setAdditionalParameters(['-hls_time', '10']); // 设置每个切片的时长（单位为秒）
        $format->setAdditionalParameters(['-hls_list_size', '0']); // 设置 HLS 播放列表的大小（0 表示不限制列表大小）
        $format->setAdditionalParameters(['-hls_segment_filename', "{$outputDir}output_%03d.ts"]); // 设置切片文件的命名格式
        $format->setAdditionalParameters(['-var_stream_map', 'v:0,a:0']); // 设置输出流映射，v:0 表示第一个视频流，a:0 表示第一个音频流
        $format->setAdditionalParameters(['-threads', '4']); // 设置线程数为 4
        // 输出为 HLS 格式
        $video->save($format, "{$outputDir}playlist.m3u8");
    }

    //将原始 MP4 文件转换为高清（HD）和标清（SD）两种分辨率的 HLS 格式
    protected function mp4ToHdAndSdHls($inputFile, $outputDir)
    {
        // 打开输入文件
        $video = $this->getFFmpeg()->open($inputFile);

        // 设置高清输出格式和编码器
        $hdFormat = new X264(); // 可以替换为其他支持的格式
        $hdFormat->setKiloBitrate(3000); // 设置高清的比特率（单位为 kb/s）
        // 设置目标分辨率
        $hdResolution = new Dimension(1280, 720);
        $video->filters()->resize($hdResolution); // 设置高清的分辨率为 1280x720

        // 设置标清输出格式和编码器
        $sdResolution = new Dimension(640, 360);
        $sdFormat = new X264(); // 可以替换为其他支持的格式
        $sdFormat->setKiloBitrate(1000); // 设置标清的比特率（单位为 kb/s）
        $video->filters()->resize($sdResolution); // 设置标清的分辨率为 640x360
        // 设置输出路径和文件名格式
        $hdFormat->setAdditionalParameters(['-hls_time', '10']); // 设置每个切片的时长（单位为秒）
        $hdFormat->setAdditionalParameters(['-hls_list_size', '0']); // 设置 HLS 播放列表的大小（0 表示不限制列表大小）
        $hdFormat->setAdditionalParameters(['-hls_segment_filename', "{$outputDir}hd_output_%03d.ts"]); // 设置切片文件的命名格式
        $hdFormat->setAdditionalParameters(['-var_stream_map', 'v:0,a:0']); // 设置输出流映射，v:0 表示第一个视频流，a:0 表示第一个音频流

        $sdFormat->setAdditionalParameters(['-hls_time', '10']); // 设置每个切片的时长（单位为秒）
        $sdFormat->setAdditionalParameters(['-hls_list_size', '0']); // 设置 HLS 播放列表的大小（0 表示不限制列表大小）
        $sdFormat->setAdditionalParameters(['-hls_segment_filename', "{$outputDir}sd_output_%03d.ts"]); // 设置切片文件的命名格式
        $sdFormat->setAdditionalParameters(['-var_stream_map', 'v:0,a:0']); // 设置输出流映射，v:0 表示第一个视频流，a:0 表示第一个音频流
        // 输出为 HLS 格式
        $video->save($hdFormat, "{$outputDir}hd_playlist.m3u8");
        $video->save($sdFormat, "{$outputDir}sd_playlist.m3u8");
    }


    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->getBiz()->service('Attachment:AttachmentService');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->getBiz()->service('Setting:SettingService');
    }

    /**
     * @return FFMpeg|null
     */
    protected function getFFmpeg()
    {
        return $this->getBiz()->offsetGet('ffmpeg');
    }

    protected function getBiz()
    {
        return Core::instance();
    }
}