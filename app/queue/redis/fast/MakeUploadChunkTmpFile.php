<?php


namespace app\queue\redis\fast;


use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Core;
use Webman\RedisQueue\Consumer;

/**
 * 分片上传-生成临时文件
 *
 * Class MakeUploadChunkTmpFile
 * @package app\queue\redis\fast
 */
class MakeUploadChunkTmpFile implements Consumer
{
    public $queue = 'make-upload-chunk-tmp-file';
    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    public function consume($data)
    {
        if (empty($data['chunkFile'])  || !is_file($data['chunkFile'])) {
            return;
        }

        // 这里需要处理逻辑：记录最后一次写入的分片号，如果当前的分片号<上次的分片号，则抛弃---需要使用循环
        $this->getAttachmentService()->mergeTmpFile($data['chunkFile'], $data['hash']);
//        $path = pathinfo($data['chunkFile'], PATHINFO_DIRNAME);
////        $ext = pathinfo($data['chunkFile'], PATHINFO_EXTENSION);
//        $tmpIndexFile = sprintf("%s/index_tmp", $path);
//        if (!is_file($tmpIndexFile) && touch($tmpIndexFile)) {}
//        $tmpIndexFp = fopen($tmpIndexFile, 'r');
//        if (flock($tmpIndexFp, LOCK_EX)) {
//            try {
//                $tmpFile = sprintf("%s/tmp", $path);
//                $chunkFiles = \preg_find_dir_files($path, "{$data['hash']}-*", true);
//                $body = file_get_contents($tmpIndexFile);
//                $prevChunkIndexItems = empty($body) ? [] : explode('|', $body);
//                for ($i = 1; $i <= count($chunkFiles); $i++) {
//                    if (in_array($i, $prevChunkIndexItems)) {
//                        continue;
//                    }
//                    $prevChunkIndexItems[] = $i;
//                    $this->writeTmpFile($tmpFile, $chunkFiles[$i - 1]);
//                    file_put_contents($tmpIndexFile, implode('|', $prevChunkIndexItems));
//                    break;
//                }
//
//                $chunkFiles = [];
//            } catch (\Throwable $e) {
//                return false;
//            } finally {
//                flock($tmpIndexFp, LOCK_UN); // 释放锁
//                fclose($tmpIndexFp);
//            }
//        }

        return true;
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->getBiz()->service('Attachment:AttachmentService');
    }

    protected function getBiz()
    {
        return Core::instance();
    }

    protected function writeTmpFile($tmpFile, $chunkFile)
    {
        $fp = fopen($tmpFile, 'ab+');
        $chunkFp = fopen($chunkFile, 'rb');
        while (!feof($chunkFp)) {
            fwrite($fp, fread($chunkFp, 1024 * 1024 * 5));
        }

        fclose($chunkFp);
        fclose($fp);
    }
}