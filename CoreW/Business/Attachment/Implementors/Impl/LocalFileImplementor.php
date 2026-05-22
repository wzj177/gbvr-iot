<?php


namespace CoreW\Business\Attachment\Implementors\Impl;


use CoreW\Business\Attachment\Exception\AttachmentException;
use CoreW\Business\Attachment\Implementors\AbstractFileImplementor;
use CoreW\Business\Attachment\Implementors\FileImplementor;
use Imagine\Image\Box;
use support\utils\StringToolkit;

//use Webman\Http\UploadFile;
use CoreW\Webman\UploadFile;

class LocalFileImplementor extends AbstractFileImplementor implements FileImplementor
{

    /**
     *
     * 获取文件
     * @param null|array $file upload_files 表数据 可以是model对象 可以是数组
     * @return mixed|array|null
     */
    public function getFile($file)
    {
        // TODO: Implement getFile() method.
    }


    /**
     * @param UploadFile $file
     * @param $path
     * @param $name
     * @param array $options
     * @return false|UploadFile|string
     */
    public function store(UploadFile $file, $path, $name = null, array $options = [])
    {
        $group = $options['group'] ?? 'default';
        $algo = $options['hash_algo'] ?? 'sha256';
        $isSaveThumbImage = $options['isSaveThumbImage'] ?? false;
        $thumbImageOptions = $options['thumbImageOptions'] ?? [];
        empty($name) && $name = time();
        $basePath = $group . DIRECTORY_SEPARATOR . (!empty($path) ? $path . DIRECTORY_SEPARATOR : '');
        $path = $basePath . $name . '.' . $file->getUploadExtension();
        if (!$file->isValid()) {
            throw AttachmentException::UPLOAD_TRANS_FAILED();
        }

        $filepath = 'uploads' . DIRECTORY_SEPARATOR . $path;
        $hashId = hash_file($algo, $file->getRealPath());
        $existFile = $this->getFileByHashId($hashId);
        $filename = $file->getUploadName();
        if (!empty($existFile) && is_file(uploads_path(str_replace('uploads/', '', $existFile['filepath'])))) {
            return [
                'storage'      => 'local',
                'filename'     => $filename,
                'newFilename'  => $existFile['newFilename'],
                'ext'          => $existFile['ext'],
                'metas'        => $existFile['metas'],
                'fileSize'     => $existFile['fileSize'],
                'type'         => $existFile['type'],
                'filepath'     => $existFile['filepath'],
                'hashId'       => $hashId,
                'groupCode'    => $existFile['groupCode'],
                'thumbPath'    => $existFile['thumbPath'],
                'firstStorage' => false,
            ];
        }

        $fileSize = $file->getSize();
        $ext = $file->getUploadExtension();
        $metas = $file->getUploadMimeType();
        $type = $this->getAttachmentTypeByUploadFile($file);
        $thumbPath = '';
        $absolutePath = uploads_path() . DIRECTORY_SEPARATOR . $path;
        $file->move($absolutePath, 0755);
        if ($isSaveThumbImage) {
            [$thumbWidth, $thumbHeight] = $thumbImageOptions['box'] ?? [];
            if (!empty($thumbWidth) && !empty($thumbHeight)) {
                $imageBox = new Box($thumbWidth, $thumbHeight);
                try {
                    $image = $this->getImagine()->open($absolutePath);
                    $thumbPath = !empty($options['thumbPath']) ? $options['thumbPath'] : $basePath . $name . '_thumb.' . $file->getUploadExtension();
                    $absoluteThumbPath = uploads_path() . DIRECTORY_SEPARATOR . $thumbPath;
                    $thumbPath = 'uploads' . DIRECTORY_SEPARATOR . $thumbPath;
                    $image->resize($imageBox)->save($absoluteThumbPath);
                    $image = null;
                } catch (\Throwable $e) {
                }
            }
        }

        return [
            'storage'      => 'local',
            'filename'     => $filename,
            'newFilename'  => $name . '.' . $ext,
            'ext'          => $ext,
            'metas'        => $metas,
            'fileSize'     => $fileSize,
            'type'         => $type,
            'filepath'     => $filepath,
            'hashId'       => $hashId,
            'groupCode'    => $group,
            'thumbPath'    => $thumbPath,
            'firstStorage' => true,
        ];
    }

    /**
     * base64流上传存储
     *
     * @param $base64Str
     * @param $path
     * @param $name
     * @return array|null [path,filename,ext]
     */
    public function storeBase64ImageFile($base64Str, $path, $name = null, array $options = [])
    {
        if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64Str, $result)) {
            $ext = $result[2];
            if (!in_array($ext, $this->config['allow_image_exts'])) {
                throw AttachmentException::IMAGE_FILE_EXT_INVALID();
            }
            $group = $options['group'] ?? 'default';
            $algo = $options['hash_algo'] ?? 'sha256';
            empty($name) && $name = time();
            $dir = $group . DIRECTORY_SEPARATOR . $path;
            $path = $dir . DIRECTORY_SEPARATOR . $name . '.' . $ext;
            $content = base64_decode(str_replace($result[1], '', $base64Str));
            $file_size = StringToolkit::byte_length($content);
            if ($this->config['allow_image_upload_size'] * 1024 < $file_size) {
                throw new AttachmentException(AttachmentException::IMAGE_FILE_SIZE_INVALID, "图片大小不能超过" . sprintf("%.2f", $this->config['allow_image_upload_size'] / 1024) . "M");
            }

            $filepath = 'uploads' . DIRECTORY_SEPARATOR . $path;
            $dir_path = uploads_path() . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($dir_path)) {
                mkdir($dir_path, 0755, true);
            }
            $file = $dir_path . DIRECTORY_SEPARATOR . $name . '.' . $ext;
            file_put_contents($file, base64_decode(str_replace($result[1], '', $base64Str)));

            return [
                'storage'     => 'local',
                'filename'    => $name . '.' . $ext,
                'newFilename' => $name . '.' . $ext,
                'ext'         => $ext,
                'metas'       => 'image/' . $ext,
                'fileSize'    => $file_size,
                'type'        => 'image',
                'filepath'    => $filepath,
                'hashId'      => hash_file($algo, $file),
                'groupCode'   => $group,
            ];
        }

        throw AttachmentException::BASE64_IMAGE_INVALID();
    }

    /**
     * 远程文件上传存储
     *
     * @param $url
     * @param $path
     * @param $name
     * @return array|null [path,filename,ext]
     */
    public function storeRemoteFile($url, $path, $name = null, array $options = [])
    {
        $url = str_replace("&amp;", "&", $url);
        // http开头验证
        if (strpos($url, "http") !== 0) {
            throw AttachmentException::REMOTE_FILE_LINK_INVALID();
        }
        //        //获取请求头并检测死链
        $heads = get_headers($url, 1);
        if (!(stristr($heads[0], "200") && stristr($heads[0], "OK"))) {
            throw AttachmentException::REMOTE_FILE_LINK_DEAD();
        }

        try {
            //打开输出缓冲区并获取远程图片
            ob_start();
            $context = stream_context_create(
                [
                    'http' => [
                        'follow_location' => false, // don't follow redirects
                    ],
                ]
            );
            //            $fp = fopen($url, 'rb', false, $context);
            //            stream_set_timeout($fp, 30);
            readfile($url, false, $context);
            $content = ob_get_contents();
            //            $content = '';
            //            while (!feof($fp)) {
            //                $content = fread($fp, 1024);
            //            }
            ob_end_clean();
            //            fclose($fp);
            $urlPath = parse_url($url, PHP_URL_PATH);
            $pathInfo = explode('.', $urlPath);
            // TODO: 可能有bug
            $ext = $this->getFileExtensionFromContentType($heads['Content-Type']);
            if (empty($ext)) {
                $ext = end($pathInfo);
            }
            $file_size = StringToolkit::byte_length($content);
            $type = $this->getAttachmentTypeByExt($ext);
            if (\attachment_validate_upload_file($this->config, $ext, $file_size)) {
                $group = $options['group'] ?? 'default';
                $algo = $options['hash_algo'] ?? 'sha256';
                empty($name) && $name = time();
                $dir = $group . DIRECTORY_SEPARATOR . $path;
                $dir_path = uploads_path() . DIRECTORY_SEPARATOR . $dir;
                if (!is_dir($dir_path)) {
                    mkdir($dir_path, 0755, true);
                }

                $filepath = 'uploads' . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $name . '.' . $ext;
                $file = $dir_path . DIRECTORY_SEPARATOR . $name . '.' . $ext;
                file_put_contents($file, $content);

                return [
                    'storage'     => 'local',
                    'filename'    => $name . '.' . $ext,
                    'newFilename' => $name . '.' . $ext,
                    'ext'         => $ext,
                    'metas'       => 'image/' . $ext,
                    'fileSize'    => $file_size,
                    'type'        => $type,
                    'filepath'    => $filepath,
                    'hashId'      => hash_file($algo, $file),
                    'groupCode'   => $group,
                ];
            }

        } catch (\Throwable $e) {
            throw  $e;
        }

    }


    /**
     * 删除文件
     *
     * @param null|array $file
     * @return mixed|boolean
     */
    public function deleteFile($file)
    {
        if (is_file($file)) {
            @unlink($file);
            return true;
        }

        return false;
    }

    protected function getFileByHashId(string $hashId)
    {
        return $this->getAttachmentService()->getAttachmentByHashId($hashId);
    }
}
