<?php

namespace CoreW\Webman;

use Webman\Exception\FileException;
use Webman\File;

class UploadFile extends \Webman\Http\UploadFile
{

    public function move(string $destination, int $dirMode = 0777, int $destinationMode = 0666) : File
    {
        set_error_handler(function ($type, $msg) use (&$error) {
            $error = $msg;
        });
        $path = pathinfo($destination, PATHINFO_DIRNAME);
        if (!is_dir($path) && !mkdir($path, $dirMode, true)) {
            restore_error_handler();
            throw new FileException(sprintf('Unable to create the "%s" directory (%s)', $path, strip_tags($error)));
        }
        if (!rename($this->getPathname(), $destination)) {
            restore_error_handler();
            throw new FileException(sprintf('Could not move the file "%s" to "%s" (%s)', $this->getPathname(), $destination, strip_tags($error)));
        }
        restore_error_handler();
        @chmod($destination, $destinationMode & ~umask());

        return new File($destination);
    }
}