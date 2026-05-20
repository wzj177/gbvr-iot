<?php

namespace support\utils;

use Imagine\Image\Box;
use Imagine\Image\Point;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class ImageToolkit
{
    public static function crop($rawImage, $targetPath, $x, $y, $width, $height, $resizeWidth = 0, $resizeHeight = 0)
    {
        $image = $rawImage->copy();

        $image->crop(new Point($x, $y), new Box($width, $height));
        if ($resizeWidth > 0 && $resizeHeight > 0) {
            $image->resize(new Box($resizeWidth, $resizeHeight));
        }

        $image->save($targetPath);

        return $image;
    }

    public static function resize($image, $targetPath, $resizeWidth = 0, $resizeHeight = 0)
    {
        $image->resize(new Box($resizeWidth, $resizeHeight));
        $image->save($targetPath);

        return $image;
    }

    public static function cropImages($filePath, $options)
    {
        $fileSystem = new Filesystem();
        $pathinfo = pathinfo($filePath);
        $filesize = filesize($filePath);
        $imagine = static::createImagine();
        $rawImage = $imagine->open($filePath);
        $naturalSize = $rawImage->getSize();
        $naturalWidth = $naturalSize->getWidth();
        $naturalHeight = $naturalSize->getHeight();
        $rate = $naturalSize->getWidth() / $options['width'];

        $options['w'] = $rate * $options['w'];
        $options['h'] = $rate * $options['h'];
        $options['x'] = $rate * $options['x'];
        $options['y'] = $rate * $options['y'];

        $filePaths = [];
        if (!empty($options['imgs']) && count($options['imgs']) > 0) {
            foreach ($options['imgs'] as $key => $value) {
                $savedFilePath = "{$pathinfo['dirname']}/{$pathinfo['filename']}_{$key}.{$pathinfo['extension']}";
                //原始尺寸等于要求的尺寸 并且 裁切的范围等于原始尺寸，不做裁切
                $isCopy = ($naturalWidth == $value[0] && $options['w'] == $value[0]) && ($naturalHeight == $value[1] && $options['h'] == $value[1]) && ($filesize < 102400);

                if ($isCopy) {
                    $filePaths[$key] = $savedFilePath;
                    $fileSystem->copy($filePath, $savedFilePath);
                } else {
                    $image = static::crop($rawImage, $savedFilePath, $options['x'], $options['y'], $options['w'], $options['h'], $value[0], $value[1]);
                    $filePaths[$key] = $savedFilePath;
                }
            }
        } else {
            $savedFilePath = "{$pathinfo['dirname']}/{$pathinfo['filename']}.{$pathinfo['extension']}";
            $image = static::crop($rawImage, $savedFilePath, $options['x'], $options['y'], $options['w'], $options['h']);
            $filePaths[] = $savedFilePath;
        }

        return $filePaths;
    }

    public static function reduceImgQuality($fullPath, $level = 10)
    {
        $extension = strtolower(substr(strrchr($fullPath, '.'), 1));

        $options = [];

        if (in_array($extension, ['jpg', 'jpeg'])) {
            $options['jpeg_quality'] = $level * 10;
        } else if ('png' == $extension) {
            $options['png_compression_level'] = $level;
        } else {
            return $fullPath;
        }

        try {
            $imagine = static::createImagine();
            $image = $imagine->open($fullPath)->save($fullPath, $options);
        } catch (\Exception $e) {
            throw new IOException($e->getMessage());
        }
    }

    public static function getImgInfo($fullPath, $width, $height)
    {
        try {
            $imagine = static::createImagine();
            $image = $imagine->open($fullPath);
        } catch (\Exception $e) {
            throw new IOException($e->getMessage());
        }

        $naturalSize = $image->getSize();
        $scaledSize = $naturalSize->widen($width)->heighten($height);

        return [$naturalSize, $scaledSize];
    }

    //将图片旋转正确
    public static function imagerotatecorrect($path)
    {
        try {
            $angle = static::getImagerotateAngle($path);
            if (!empty($angle)) {
                $image = imagecreatefromstring(file_get_contents($path));
                $image = imagerotate($image, $angle, 0);
                imagejpeg($image, $path);
                imagedestroy($image);

                return $path;
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    public static function createImagine()
    {
        if (extension_loaded('imagick')) {
            return new \Imagine\Imagick\Imagine();
        }

        if (extension_loaded('gmagick')) {
            return new \Imagine\Gmagick\Imagine();
        }

        return new \Imagine\Gd\Imagine();
    }

    public static function downloadImg($url, $savePath, $mock = false)
    {
        if ($mock) {
            $fileSystem = new Filesystem();
            $fileSystem->copy($url, $savePath);

            return $savePath;
        }
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $imageData = curl_exec($curl);
        curl_close($curl);
        $tp = @fopen($savePath, 'w');
        fwrite($tp, $imageData);
        fclose($tp);

        return $savePath;
    }


    private static function getImagerotateAngle($path)
    {
        $angle = 0;
        //只旋转JPEG的图片
        if (!(extension_loaded('gd') && extension_loaded('exif') && IMAGETYPE_JPEG == exif_imagetype($path))) {
            return $angle;
        }

        $exif = @exif_read_data($path);
        if (empty($exif['Orientation'])) {
            return $angle;
        }
        switch ($exif['Orientation']) {
            case 8:
                $angle = 90;
                break;
            case 3:
                $angle = 180;
                break;
            case 6:
                $angle = -90;
                break;
        }

        return $angle;
    }
}