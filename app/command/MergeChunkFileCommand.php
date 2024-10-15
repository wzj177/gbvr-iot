<?php


namespace app\command;


use CoreW\Bfw;
use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Core;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MergeChunkFileCommand extends Command
{
    protected static $defaultName = 'upload:merge-chunk';
    protected static $defaultDescription = '合并大文件分片';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('hash', InputArgument::REQUIRED, '文件hashID');
        $this->addArgument('filepath', InputArgument::REQUIRED, '新文件路径');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $hash = $input->getArgument('hash');
        $filepath = $input->getArgument('filepath');
//        file_put_contents(runtime_path('test.log'), json_encode([
//            'hash' => $hash,
//            'filepath' => $filepath,
//        ]), FILE_APPEND);
        $chunks = $this->getAttachmentService()->getChunkFilesByHashID($hash, false);
        $fp = fopen($filepath, 'ab+');
        foreach ($chunks as $chunkFile) {
            $chunkFp = fopen($chunkFile, 'rb');
//            stream_copy_to_stream($chunkFp, $fp);
            while (!feof($chunkFp)) {
                fwrite($fp, fread($chunkFp, 1024 * 1024 * 5));
            }
            fclose($chunkFp);
        }

        fclose($fp);
//        $numChunks = count($chunks);
//        $chunkSize = 50;

        $resultFiles = [];
//        $poolSize = 4; // 设置并行处理的进程数量
//        $pool = [];
//
//        for ($i = 0; $i < $numChunks; $i += $chunkSize) {
//            $chunkSlice = array_slice($chunks, $i, $chunkSize);
//            $pool[] = pcntl_fork();
//            if (count($pool) >= $poolSize || $i + $chunkSize >= $numChunks) {
//                // 当进程池达到指定大小或到达最后一组分片时，等待所有进程完成
//                foreach ($pool as $pid) {
//                    pcntl_waitpid($pid, $status);
//                    if (pcntl_wifexited($status)) {
//                        $resultFiles[] = pcntl_wexitstatus($status);
//                        file_put_contents(runtime_path('test.log'), '主进程等待子进程退出，并获取返回结果' . microtime() . PHP_EOL, FILE_APPEND);
//                    }
//                }
//                $pool = []; // 重置进程池
//            }
//
//            if (end($pool) == 0) {
//                // 子进程中执行合并任务
//                $mergedFile = $this->mergeChunks($chunkSlice, $filepath);
//                file_put_contents(runtime_path('test.log'), 'i=' . $i . '个进程处理' . microtime() . PHP_EOL, FILE_APPEND);
//                exit($mergedFile);
//            }
//        }
//        for ($i = 0; $i < $numChunks; $i += $chunkSize) {
//            $chunkSlice = array_slice($chunks, $i, $chunkSize);
//            $resultFiles[] = $this->mergeChunks($chunkSlice, $filepath);
//            $pid = pcntl_fork();
//            if ($pid == -1) {
//                // 创建子进程失败
//                die("Fork failed.");
//            } elseif ($pid == 0) {
//                // 子进程中执行合并任务
//                $mergedFile = $this->mergeChunks($chunkSlice, $filepath);
//                file_put_contents(runtime_path('test.log'), 'i=' . $i . '个进程处理' . microtime() . PHP_EOL, FILE_APPEND);
//                exit($mergedFile);
//            } else {
//                // 主进程等待子进程退出，并获取返回结果
//                $status = null;
//                pcntl_waitpid($pid, $status);
//                if (pcntl_wifexited($status)) {
//                    $resultFiles[] = pcntl_wexitstatus($status);
//                    file_put_contents(runtime_path('test.log'), '主进程等待子进程退出，并获取返回结果' . microtime() . PHP_EOL, FILE_APPEND);
//                }
//            }
//        }

//        file_put_contents(runtime_path('test.log'), json_encode($resultFiles), FILE_APPEND);
        return self::SUCCESS;
    }


    protected function mergeChunks($chunks, $mergedFile)
    {
        $outputFile = fopen($mergedFile, 'wb');
        foreach ($chunks as $chunk) {
            $inputFile = fopen($chunk, 'rb');
            stream_copy_to_stream($inputFile, $outputFile);
            fclose($inputFile);
        }

        fclose($outputFile);
        return $mergedFile;
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->getBiz()->service('Attachment:AttachmentService');
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }

}