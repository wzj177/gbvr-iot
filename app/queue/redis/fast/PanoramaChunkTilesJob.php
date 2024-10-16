<?php

namespace app\queue\redis\fast;

use CoreW\Business\BizEnum;
use CoreW\Business\Lock\FileLock;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Core;
use CoreW\Traits\ImagineTrait;
use FilesystemIterator;
use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Imagine\Image\ManipulatorInterface;
use Imagine\Image\Point;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use support\Log;
use support\utils\AssetHelper;
use support\utils\FileToolkit;
use Webman\RedisQueue\Consumer;

class PanoramaChunkTilesJob implements Consumer
{
    use ImagineTrait;

    public $queue = 'scene-panorama-chunk-tiles';

    public $connection = 'default';

    public function consume($data): bool
    {
        if (empty($data['panorama']) || empty($data['productId']) || empty($data['userId'])) {
            return false;
        }
        $panoramaFile = AssetHelper::uploadPath($data['panorama']);
        if (!is_file($panoramaFile)) {
            Log::error('PanoramaChunkTilesJob consume data: panorama file not found');
            return false;
        }

        $size = filesize($panoramaFile);
        $vrSetting = $this->getSettingService()->get('vr', []);
        $canChunkTileSize = $vrSetting['chunk_tiles_size'] ?? 1024 * 1024 * 1;
        $image = $this->getImagine()->open($panoramaFile);
        $imageSize = $image->getSize();
        $width = $imageSize->getWidth();
        $height = $imageSize->getHeight();
        $this->getProductService()->updateSceneByProductAndIndex($data['productId'], $data['number'], [
            'panoramaWidth' => $width,
            'panoramaHeight' => $height
        ]);

        if ($size > $canChunkTileSize) {
//            $lockKey = 'panorama_chunk_tiles_' . $data['productId'] . '_' . $data['number'];
            $this->generateTiles($data['userId'], $data['productId'], $data['number'], $image, $panoramaFile);
        }

        return true;
    }

    protected function generateTiles(?int $userId, ?int $productId, int $index, $image, $panoramaFile, bool $lock = true)
    {
        Log::debug("开始生成场景切片，{$productId}");
        $partInfo = pathinfo($panoramaFile);
        $key = $partInfo['filename'] . '_p' . $productId . '_' . $index . '_tiles';
        $smallKey = $partInfo['filename'] . '_p' . $productId . '_' . $index . '_small';
        $tilesPath = $partInfo['dirname'] . DIRECTORY_SEPARATOR . $key;
        $fn = function () use ($userId, $productId, $index, $key, $tilesPath, $image, $panoramaFile, $partInfo, $smallKey) {
            $relativeTilePath = str_replace(uploads_path(), 'uploads', $tilesPath);
            try {
                $this->getProductService()->updateSceneByProductAndIndex((int)$productId, (int)$index, [
                    'tilePath' => $relativeTilePath,
                    'tileStatus' => BizEnum::PRODUCT_SCENE_TILE_STATUS_ING
                ]);
                Log::debug("生成场景切片进行中，{$productId}");
                if (!is_dir($tilesPath) && @mkdir($tilesPath, 0755)) {
                } else {
                    FileToolkit::removeDirFiles($tilesPath);
                }

                $rows = 8;
                $columns = 16;
                $imageSize = $image->getSize();
                $width = $imageSize->getWidth();
                $height = $imageSize->getHeight();
                $tileSize = (int)floor($width / $columns);
                $quality = 95;
//                $columns = intval(ceil($width / $tileSize));
//                $rows = intval(ceil($height / $tileSize));
//                $columns = $columns == $width / $tileSize ? $columns * 2 : $columns;
//                $rows = $rows == $height / $tileSize ? $rows * 2 : $rows;
                // 最接近且小于所需值的 2 的幂次数
//                $columns = pow(2, floor(log($width / $tileSize, 2)));
//                $rows = pow(2, floor(log($height / $tileSize, 2)));

                for ($x = 0; $x < $columns; $x++) {
                    for ($y = 0; $y < $rows; $y++) {
                        $startX = $x * $tileSize;
                        $startY = $y * $tileSize;
                        // 计算裁剪区域的结束坐标
                        $endX = min($startX + $tileSize, $width);
                        $endY = min($startY + $tileSize, $height);
                        // 计算裁剪区域的宽度和高度
                        $cropWidth = $endX - $startX;
                        $cropHeight = $endY - $startY;
                        // 检查裁剪区域是否在图像范围内
                        // 裁剪图像
                        if ($startX < $width && $startY < $height && $cropWidth > 0 && $cropHeight > 0) {
                            $startPoint = new Point($startX, $startY);
                            $cropSize = new Box($cropWidth, $cropHeight);
                            $image->copy()
                                ->crop($startPoint, $cropSize)
                                ->save($tilesPath . DIRECTORY_SEPARATOR . 'cp_' . $x . '_' . $y . '.jpg', ['quality' => $quality]);
                        }
                    }
                }
                $panoramaSmallPath = $partInfo['dirname'] . DIRECTORY_SEPARATOR . $smallKey . '.' . $partInfo['extension'];
                Log::debug("处理场景切片图片分辨率，{$productId}");
                $result = $this->compressPanoramaSmallImage($panoramaFile, $panoramaSmallPath);
                Log::debug("处理场景切片图片分辨率完成，{$productId}");
                $item = [
                    'tileRows' => $rows,
                    'tileColumns' => $columns,
                    'tilePath' => $relativeTilePath,
                    'tileSize' => $tileSize,
                    'tileStatus' => BizEnum::PRODUCT_SCENE_TILE_STATUS_OK
                ];
                if ($result !== false) {
                    $panoramaSize = filesize($panoramaFile) + $result[1];
                    $item['panoramaSize'] = $panoramaSize;
                    $item['panoramaSmall'] = str_replace(uploads_path(), 'uploads', $panoramaSmallPath);
                }

                $tileSize = $this->getDirectorySize($tilesPath);
                Log::debug('ok tiles', $item);
                $this->getProductService()->updateSceneByProductAndIndex((int)$productId, (int)$index, $item);
                $this->getVIPService()->addUsedSpaceSize($userId, $tileSize);
                $this->getLogService()->info('product_scene', 'chunk_panorama', '全景图切片完成', [
                    'productId' => $productId,
                    'panorama_file' => $panoramaFile,
                    'tile_path' => $tilesPath
                ]);
            } catch (\Throwable $e) {
                $this->getProductService()->updateSceneByProductAndIndex((int)$productId, (int)$index, [
                    'tilePath' => $relativeTilePath,
                    'tileStatus' => BizEnum::PRODUCT_SCENE_TILE_STATUS_ERR
                ]);
                $this->getLogService()->info('product_scene', 'chunk_panorama', '全景图切片失败，' . $e->getMessage(), [
                    'productId' => $productId,
                    'panorama_file' => $panoramaFile,
                    'tile_path' => $tilesPath
                ]);

            }
        };

        $lock ? $this->fileLock()->exec($key, $fn) : $fn();
    }

    /**
     * 生成低分辨率的全景图（大小控制在500kb～1mb）
     * @param string $originalImagePath
     * @param string $targetImagePath
     * @param int $maxSize
     * @param int $quality 初始图像质量
     * @return array|false
     */
    protected function compressPanoramaSmallImage(string $originalImagePath, string $targetImagePath, int $maxSize = 1024 * 1024, int $quality = 80)
    {
        if (filesize($originalImagePath) <= $maxSize) {
            return false;
        }

        $image = $this->getImagine()->open($originalImagePath);
        $minSize = 500 * 1024; // 500KB（以字节为单位）
        // 初始图像质量
        do {
            // 调整图像质量
            $image->save($targetImagePath, ['quality' => $quality]);
            clearstatcache($targetImagePath);
            // 检查文件大小
            $fileSize = filesize($targetImagePath);
//            echo 'current $fileSize:', $fileSize, PHP_EOL;
            if ($fileSize <= $minSize) {
                break;
            }

            // 如果大小超过上限，则按比例缩小图像
            $size = $image->getSize();
            $newWidth = $size->getWidth() * 0.9; // 缩小为原来的 90%
            $newHeight = $size->getHeight() * 0.9; // 缩小为原来的 90%
            $image = $image->resize(new Box($newWidth, $newHeight));
            // 更新图像质量
            $quality -= 5; // 递减质量
//            sleep(1);
        } while ($fileSize > $maxSize);

        // 保存处理后的图像
        $image->save($targetImagePath);

        return [$targetImagePath, $fileSize];
    }

    protected function getDirectorySize($path)
    {
        $totalSize = 0;

        try {
            // 使用 FilesystemIterator::SKIP_DOTS 以跳过 . 和 .. 目录
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                // 跳过隐藏文件和目录
                if (strpos($file->getFilename(), '.') === 0) {
                    continue; // 跳过隐藏文件或目录
                }

                // 检查是否是 .jpg 文件
                if ($file->isFile() && strtolower($file->getExtension()) === 'jpg') {
                    $totalSize += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            // 捕获异常并记录日志
            Log::debug("Error reading tiles file computed file size: " . $e->getMessage());
        }

        return $totalSize;
    }

    protected function removeTileFiles($path)
    {
        foreach (glob($path . '/*.jpg') as $file) {
            @unlink($file);
        }
    }

    protected function fileLock()
    {
        return new FileLock();
    }

    /**
     * @return ProductService
     */
    protected function getProductService()
    {
        return $this->getBiz()->service('Product:ProductService');
    }

    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->getBiz()->service('VIP:VIPService');
    }

    /**
     * @return SystemLogService
     */
    protected function getLogService()
    {
        return $this->getBiz()->service('SystemLog:SystemLogService');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->getBiz()->service('Setting:SettingService');
    }

    protected function getBiz()
    {
        return Core::instance();
    }
}