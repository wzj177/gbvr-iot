<?php

namespace CoreW\Gii;

use CoreW\Bfw;
use CoreW\Gii\Template\BaseProcessor;

class GiiFactory
{

    /**
     * @param $type
     * @param string $namespacePrefix
     * @param Bfw $biz
     * @return BaseProcessor
     * @throws GiiException
     */
    public static function create($type, string $namespacePrefix, Bfw $biz)
    {
        $type = ucfirst($type);
        $class = __NAMESPACE__ . "\\Template\\{$type}\\{$type}Processor";
        if (class_exists($class)) {
            return new $class($namespacePrefix, $biz);
        }

        throw GiiException::bizTemplateNotExist($type . ' biz模板不存在');
    }

    public static function clear($path)
    {
        try {
//            shell_exec("rm -rf {$path}"); -- 存在一个问题：shell会开启另外一个进程处理，在清空在重新生成时，当前进程可能获取到目录仍然存在
            self::delDir($path);
        } catch (\Throwable $e) {
            throw  GiiException::clearServiceFilesFailed($e->getMessage());
        }
    }

    private static function delDir($directory)
    {
        if (is_dir($directory)) {
            if ($dir_handle = @opendir($directory)) {
                while ($filename = readdir($dir_handle)) {
                    if ($filename != '.' && $filename != '..') {
                        $subFile = $directory . "/" . $filename;
                        if (is_dir($subFile)) {
                            self::delDir($subFile);
                        }
                        if (is_file($subFile)) {
                            unlink($subFile);
                        }
                    }
                }
                closedir($dir_handle);
                rmdir($directory);
            }
        }
    }
}
