<?php

namespace app\queue\redis\fast;

use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Core;
use support\utils\AssetHelper;
use support\utils\FileToolkit;
use support\utils\StringToolkit;
use Webman\RedisQueue\Consumer;

class DeleteSceneJob implements Consumer
{
    public $queue = 'delete-scene';

    public $connection = 'default';

    public function consume($data) : bool
    {
        $product = $data['product'];
        $scene = $data['scene'];
        $delProduct = $data['delProduct'] ?? false;
        if (empty($scene['id'])) {
            return false;
        }

        try {
            $tilePath = AssetHelper::uploadPath($scene['tilePath']);
            $panoramaPath = AssetHelper::uploadPath($scene['panorama']);
            $panoramaSmallPath = AssetHelper::uploadPath($scene['panoramaSmall']);
            $thumbPath = AssetHelper::uploadPath($scene['thumb']);
            $msg = '';
            $usedSize = $scene['panoramaSize'] + $scene['tileSize'];

            if (!empty($scene['panorama']) && is_file($panoramaPath)) {
                if (!empty($scene['prFileId'])) {
                    $this->getAttachmentService()->deleteAttachmentById($scene['prFileId']);
                } else {
                    @unlink($panoramaPath);
                }

                // TODO：删除资源表
                $msg .= "全景图文件已删除;";
                $usedSize = $scene['panoramaSize'];
            }

            if (!empty($scene['panoramaSmall']) && is_file($panoramaSmallPath)) {
                @unlink($panoramaSmallPath);
                $msg .= "全景图低分辨率文件已删除;";
            }

            if (!empty($scene['thumb']) && is_file($thumbPath)) {
                @unlink($thumbPath);
                $msg .= "全景图缩略图文件已删除;";
                $usedSize += $scene['thumbSize'];
                if (!$delProduct && $product['cover'] === $scene['thumb']) {
                    // 删除场景,如果遇到场景是作品的默认封面,则将作品封面置空
                    $this->getProductService()->setProductCover($product['id'], '');
                }
            }

            if (!empty($scene['tilePath']) && is_dir($tilePath)) {
                FileToolkit::removeDirFiles($tilePath);
                FileToolkit::remove($tilePath);
                $msg .= "全景图瓦片tiles目录已清空删除;";
            }

            $this->getVIPService()->subUsedSpaceSize($product['userId'], $usedSize);
            $msg .= "用户空间减少:" . StringToolkit::printMem($usedSize);
            $this->getLogService()->error(LogEnum::MODULE_PRODUCT_SCENE, LogEnum::ACTION_DELETE_SCENE_AFTER, "清理场景(ID:{$scene['id']})资源文件完成:{$msg}", $scene);

            return true;
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_PRODUCT_SCENE, LogEnum::ACTION_DELETE_SCENE_AFTER, '清理场景资源文件失败,' . $e->getMessage(), $scene);
            return false;
        }
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
     * @return ProductService
     */
    protected function getProductService()
    {
        return $this->getBiz()->service('Product:ProductService');
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
}