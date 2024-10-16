<?php

namespace CoreW\Business\Product\Service\Impl;

use CoreW\Business\Attachment\Dto\FileUploadDto;
use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Business\BaseService;
use CoreW\Business\BizEnum;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Product\Dao\ProductHotPointDao;
use CoreW\Business\Product\Dao\ProductPlaneGraphDao;
use CoreW\Business\Product\Dao\ProductSceneDao;
use CoreW\Business\Product\Dao\ProductSettingDao;
use CoreW\Business\Product\Dao\ProductTagDao;
use CoreW\Business\Product\Dao\ProductTourDao;
use CoreW\Business\Product\Dao\ProductTourNodeDao;
use CoreW\Business\Product\Dto\PlaneGraphMarkersDto;
use CoreW\Business\Product\Dto\ProductConfigDto;
use CoreW\Business\Product\Exception\ProductException;
use CoreW\Business\Product\Service\ProductCatalogService;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Business\Product\Dao\ProductDao;
use CoreW\Business\Product\Service\ProductTagService;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\User\Service\UserService;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Util\ExpiredTimeToToken;
use Respect\Validation\Validator as v;
use support\Log;
use support\utils\ArrayToolkit;
use support\utils\AssetHelper;
use Webman\RedisQueue\Client;
use Ramsey\Uuid\Uuid;

/***
 * @package  核心业务:作品
 */
class ProductServiceImpl extends BaseService implements ProductService
{

    /**
     * 通过作品+序号 更新 场景
     *
     * @param int $productId
     * @param int $index
     * @param array $fields
     * @return false|int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function updateSceneByProductAndIndex(int $productId, int $index, array $fields)
    {
        $scene = $this->getSceneDao()->getByProductAndIndex($productId, $index);
        if (empty($scene)) {
            return false;
        }

        $this->getSceneDao()->update(['productId' => $productId, 'number' => $index], $fields);

        return true;
    }

    /**
     * @param array $conditions
     * @param array $orderBys
     * @param $start
     * @param $limit
     * @param $columns
     * @return array|array[]|mixed
     */
    public function searchScenes(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        if (empty($conditions['productId'])) {
            return [];
        }

        return $this->getSceneDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    /**
     * @param int $sceneId
     * @return array|mixed|null
     */
    public function getScene(int $sceneId)
    {
        $scene = $this->getSceneDao()->get($sceneId);
        if (empty($scene)) {
            throw ProductException::NOT_FOUND_PRODUCT_SCENE();
        }

        return $scene;
    }

    /**
     * @param int $productId
     * @param FileUploadDto $uploadDto
     * @param array $fields
     * @return mixed
     * @throws \CoreW\Dao\DaoException
     * @throws \Throwable
     */
    public function addScene(int $productId, FileUploadDto $uploadDto, array $fields)
    {
        $product = $this->getProductDao()->get($productId);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        $result = $this->getAttachmentService()->uploadFile($uploadDto);
        if (empty($result['path'])) {
            throw ProductException::CREATE_SCENE_UPLOAD_ERROR();
        }
        [$panoramaFile, $panoramaThumbFile] = $this->filterPanoramaByPathAndThumbPath($result['path'], $result['thumbPath']);
        try {
            $this->beginTransaction();
            $panoramaFileSize = filesize($panoramaFile);
            $panoramaThumbFileSize = $panoramaThumbFile ? filesize($panoramaThumbFile) : 0;
            // 获取lasted scene
            $index = isset($fields['index']) ? (int)$fields['index'] + 1 : 0;
            $scene = $this->getSceneDao()->create([
                'productId' => $product['id'],
                'number' => $index,
                'title' => '场景' . ($index + 1),
                'panorama' => $result['path'],
                'thumb' => $result['thumbPath'],
                'prFileId' => $result['fileId'],
                'panoramaSize' => $panoramaFileSize,
                'thumbSize' => $panoramaThumbFileSize,
            ]);
            $usedSpaceSize = $panoramaFileSize + $panoramaFileSize;
            $this->getVIPService()->addUsedSpaceSize($fields['userId'], $usedSpaceSize);
            $this->commit();
            $this->getLogService()->info('product', 'add_scene', '作品添加场景成功,user_id=' . $fields['userId'] . ', id=' . $product['id'], [
                'userId' => $fields['userId'],
                'currentIp' => $fields['currentIp'] ?? ''
            ]);
            Client::send('scene-panorama-chunk-tiles', $scene);
            return $scene;
        } catch (\Throwable $e) {
            $this->rollback();
            is_file($panoramaFile) && @unlink($panoramaFile);
            ($panoramaThumbFile && is_file($panoramaThumbFile)) && @unlink($panoramaThumbFile);
            $this->getLogService()->info('product', 'add_scene', '作品添加场景失败,user_id=' . $fields['userId'] . ', id=' . $product['id'], [
                'userId' => $fields['userId'],
                'currentIp' => $fields['currentIp'] ?? ''
            ]);
            throw $e;
        }
    }

    public function updateScene(int $sceneId, array $fields)
    {
        $scene = $this->getSceneDao()->get($sceneId);
        if (empty($scene)) {
            throw ProductException::NOT_FOUND_PRODUCT_SCENE();
        }
        $fields = ArrayToolkit::parts($fields, ['title', 'vrOptions', 'status', 'number']);
        if (!empty($fields['vrOptions'])) {
            if (isset($fields['defaultLong'])) {
                $fields['longitude'] = $fields['vrOptions']['defaultLong'];
            }

            if (isset($fields['defaultLat'])) {
                $fields['latitude'] = $fields['vrOptions']['defaultLat'];
            }

            if (isset($fields['caption'])) {
                $fields['caption'] = $fields['vrOptions']['caption'];
            }

            if (isset($fields['minFov'])) {
                $fields['minFov'] = $fields['vrOptions']['minFov'];
            }

            if (isset($fields['maxFov'])) {
                $fields['maxFov'] = $fields['vrOptions']['maxFov'];
            }

            if (isset($fields['defaultZoomLvl'])) {
                $fields['defaultZoomLvl'] = $fields['vrOptions']['defaultZoomLvl'];
            }

            if (isset($fields['description'])) {
                $fields['description'] = $fields['vrOptions']['description'];
            }

            if (isset($fields['sphereCorrection']['pan'])) {
                $fields['pan'] = $fields['vrOptions']['sphereCorrection']['pan'];
            }
            if (isset($fields['sphereCorrection']['tilt'])) {
                $fields['tilt'] = $fields['vrOptions']['sphereCorrection']['tilt'];
            }
            if (isset($fields['sphereCorrection']['roll'])) {
                $fields['roll'] = $fields['vrOptions']['sphereCorrection']['roll'];
            }

        }

        return $this->getSceneDao()->update($sceneId, $fields);
    }

    /**
     * 交换场景顺序
     *
     * @param int $sceneId
     * @param array $fields
     * @return bool
     */
    public function updateSceneSortNum(int $sceneId, array $fields)
    {
        if (empty($fields['to_scene_id'])) {
            throw  CommonBizException::ERROR_PARAMETER();
        }

        $scene = $this->getSceneDao()->get($sceneId);
        if (empty($scene)) {
            throw ProductException::NOT_FOUND_PRODUCT_SCENE();
        }

        $toScene = $this->getSceneDao()->get($fields['to_scene_id']);
        if (empty($toScene)) {
            throw ProductException::NOT_FOUND_PRODUCT_SCENE();
        }

        $this->beginTransaction();
        try {
            $this->getSceneDao()->update($toScene['id'], ['number' => $scene['number']]);
            $this->getSceneDao()->update($scene['id'], ['number' => $toScene['number']]);
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            return false;
        }
    }

    public function deleteScene(int $sceneId)
    {
        $scene = $this->getSceneDao()->get($sceneId);
        if (empty($scene)) {
            throw ProductException::NOT_FOUND_PRODUCT_SCENE();
        }

        $product = $this->getProductById($scene['productId']);
        $result = $this->getSceneDao()->delete($sceneId);
        if ($result) {
            Client::send('delete-scene', ['product' => $product, 'scene' => $scene]);
            return true;
        }

        return false;
    }


    /**
     * 场景上传配置参数
     *
     * @return array
     */
    public function getSceneUploadConfig()
    {
        $attachmentConfig = $this->getSettingService()->get('attachment', []);

        $config = [
            'image_max_upload_size' => $attachmentConfig['allow_image_upload_size'] ?? config('server.max_package_size'),
            'video_max_upload_size' => $attachmentConfig['allow_video_upload_size'] ?? config('server.max_package_size'),
            'file_max_upload_size' => $attachmentConfig['allow_file_upload_size'] ?? config('server.max_package_size'),
        ];
        $config['image_max_upload_size_txt'] = round($config['image_max_upload_size'] / 1024, 2) . 'MB';
        $config['video_max_upload_size_txt'] = round($config['video_max_upload_size'] / 1024, 2) . 'MB';
        $config['file_max_upload_size_txt'] = round($config['file_max_upload_size'] / 1024, 2) . 'MB';

        return $config;
    }

    public function getProductById($id): ?array
    {
        $product = $this->getProductDao()->get($id);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        return $this->mapProduct($product);
    }

    /**
     * 获取作品详情
     *
     * @param $id
     * @return array|null
     */
    public function getProductByCode(string $code): ?array
    {
        $product = $this->getProductDao()->getByCode($code);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        return $this->mapProduct($product);
    }

    protected function mapProduct(array $product): array
    {
        $scenes = $this->getSceneDao()->getAllByProductId((int)$product['id']);
        $tags = $this->getProductTagDao()->getAll(['productId' => $product['id']], null, ['tagType', 'tagId', 'tagName']);
        $product['recommendTagIds'] = ArrayToolkit::column(array_filter($tags, function ($tag) {
            return $tag['tagType'] === 'system';
        }), 'tagId');
        $product['customTags'] = ArrayToolkit::column(array_filter($tags, function ($tag) {
            return $tag['tagType'] !== 'system';
        }), 'tagName');
        $product['scenes'] = $scenes;
        $product['tags'] = $tags;
        $user = $this->getVIPService()->getVIPById($product['userId']);
        $product['userName'] = $user['nickname'];

        return $product;
    }

    public function countProducts(array $conditions)
    {
        return $this->getProductDao()->count($conditions);
    }

    public function searchProducts(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        $items = $this->getProductDao()->search($conditions, $orderBys, $start, $limit, $columns);
        $catalogIds = ArrayToolkit::column($items, 'catalogId');
        $catalogs = $this->getCatalogService()->searchProductCatalogs(['ids' => $catalogIds], [], 0, count($catalogIds));
        $catalogs = ArrayToolkit::index($catalogs, 'id');
        $ids = ArrayToolkit::column($items, 'id');
        $tags = $this->getProductTagDao()->search(['productIds' => $ids], [], 0, count($ids));
        $tags = ArrayToolkit::group($tags, 'productId');
        foreach ($items as &$item) {
            $item['catalogTitle'] = isset($catalogs[$item['catalogId']]) ? $catalogs[$item['catalogId']]['name'] : '';
            $item['tags'] = $tags[$item['id']] ?? [];
        }

        return $items;
    }

    /**
     * @param array $fields
     * @return true|int
     * @throws \Throwable
     */
    public function createProduct(array $fields)
    {
        if (!ArrayToolkit::requireds($fields, ['title', 'type', 'scenes'])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        $currentIp = $fields['currentIp'];
        $fields = $this->validateFields($fields);
        $fields['status'] = BizEnum::PRODUCT_STATUS_DRAFTED;
        $scenes = array_filter($fields['scenes'], function ($value) {
            return !empty($value['path']) && !empty($value['thumb_path']);
        });
        $recommendTagIds = $fields['recommendTagIds'] ?? [];
        $customTags = !empty($fields['customTags']) ? (is_string($fields['customTags']) ? explode('|', $fields['customTags']) : $fields['customTags']) : [];
        unset($fields['scenes'], $fields['recommendTagIds'], $fields['customTags']);
        $fields['code'] = Uuid::uuid4();
        $sceneItems = [];
        $this->beginTransaction();
        $usedSpaceSize = 0;
        try {
            foreach ($scenes as $index => $scene) {
                list($panoramaFile, $panoramaThumbFile) = $this->filterPanoramaByPathAndThumbPath($scene['path'] ?? '', $scene['thumb_path'] ?? '');
                if (empty($panoramaFile) || empty($panoramaThumbFile)) {
                    continue;
                }
                $panoramaFileSize = filesize($panoramaFile);
                $panoramaThumbFileSize = filesize($panoramaThumbFile);
                $usedSpaceSize += $panoramaFileSize + $panoramaThumbFileSize;
                $sceneItems[] = [
                    'number' => $index,
                    'title' => '场景' . ($index + 1),
                    'panorama' => $scene['path'],
                    'thumb' => $scene['thumb_path'],
                    'panoramaSize' => $panoramaFileSize,
                    'thumbSize' => $panoramaThumbFileSize,
                    'prFileId' => $scene['file_id'] ?? 0
                ];
                if (empty($fields['cover'])) {
                    $fields['cover'] = $scene['thumb_path'];
                }
            }

            if (empty($sceneItems)) {
                throw ProductException::PRODUCT_CREATE_SCENE_EMPTY_ERROR();
            }

            $product = $this->getProductDao()->create($fields);
            $sceneItems = array_map(function ($scene) use ($product) {
                $scene['userId'] = $product['userId'];
                $scene['productId'] = $product['id'];

                return $scene;
            }, $sceneItems);
            $this->getSceneDao()->batchCreate($sceneItems);
            $this->generateUserProductTags($fields['userId'], $product['id'], $recommendTagIds, $customTags);
            $this->getVIPService()->addUsedSpaceSize($fields['userId'], $usedSpaceSize);
            $this->commit();
            $this->getLogService()->info('product', 'create', '创建作品成功,user_id=' . $fields['userId'] . ', id=' . $product['id'], [
                'userId' => $fields['userId'],
                'currentIp' => $currentIp
            ]);
            foreach ($sceneItems as $sceneItem) {
                Log::info("拆分场景瓦片图片，场景路径={$sceneItem['panorama']}");
                Client::send('scene-panorama-chunk-tiles', $sceneItem);
            }

            return $product['id'];
        } catch (\Throwable $e) {
            $this->rollback();
            $this->getLogService()->error('product', 'create', '创建作品失败,user_id=' . $fields['userId'] . '，' . $e->getMessage(), [
                'userId' => $fields['userId'],
                'currentIp' => $currentIp
            ]);
            throw $e;
        }
    }

    /**
     * 更新作品信息
     *
     * @param $id
     * @param array $fields
     * @return mixed|int|true
     * @throws \CoreW\Dao\DaoException
     * @throws \Throwable
     */
    public function updateProduct($id, array $fields)
    {
        $product = $this->getProductDao()->get($id);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        if (!ArrayToolkit::requireds($fields, ['title', 'cover'])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        $currentIp = $fields['currentIp'];
        $fields = $this->validateFields($fields);
        $recommendTagIds = $fields['recommendTagIds'] ?? [];
        $customTags = !empty($fields['customTags']) ? (is_string($fields['customTags']) ? explode('|', $fields['customTags']) : $fields['customTags']) : [];
        $hotPoints = !empty($fields['hotPoints']) ? $fields['hotPoints'] : [];
        unset($fields['scenes'], $fields['recommendTagIds'], $fields['customTags'], $fields['hotPoints']);
        $this->beginTransaction();
        try {
            $product = $this->getProductDao()->update($id, $fields);
            $this->generateUserProductTags($fields['userId'], $product['id'], $recommendTagIds, $customTags);
            if (!empty($hotPoints)) {
                $updateHotpointIds = ArrayToolkit::column($hotPoints, 'id');
                $hotPoints = array_map(function ($hotPoint) use ($product) {
                    $hotPoint['productId'] = $product['id'];
                    return $hotPoint;
                }, $hotPoints);
                $updateHotPoints = ArrayToolkit::index($hotPoints, 'id');
                $this->getHotPointDao()->batchUpdate($updateHotpointIds, $updateHotPoints);
            }
            $this->commit();
            $this->getLogService()->info('product', 'update', '更新作品成功,user_id=' . $fields['userId'] . ', id=' . $product['id'], [
                'userId' => $fields['userId'],
                'currentIp' => $currentIp
            ]);
            return $product['id'];
        } catch (\Throwable $e) {
            $this->getLogService()->error('product', 'update', '更新作品失败,user_id=' . $fields['userId'] . '，' . $e->getMessage(), [
                'userId' => $fields['userId'],
                'currentIp' => $currentIp
            ]);
            throw $e;
        }
    }

    /**
     * @param $id
     * @param array $fields
     * @return bool
     * @throws \CoreW\Dao\DaoException
     */
    public function closeProduct($id, array $fields): bool
    {
        $product = $this->getProductDao()->get($id);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        if (!empty($fields['userId']) && $product['userId'] !== $fields['userId']) {
            //操作失败,无权限关闭此作品
            throw ProductException::PRODUCT_FORBIDDEN_CLOSE_USER_PRODUCT();
        }

        if ($product['status'] === BizEnum::PRODUCT_STATUS_CLOSED) {
            //操作失败,作品已关闭
            throw ProductException::PRODUCT_CLOSED();
        }

        $this->getProductDao()->update($id, [
            'status' => BizEnum::PRODUCT_STATUS_CLOSED
        ]);
        $this->getLogService()->info('product', 'close', "关闭作品《{$product['title']}》,作品ID:{$id}", [
            'userId' => $fields['userId'],
            'productId' => $id,
            'currentIp' => $fields['currentIp'] ?? '127.0.0.1'
        ]);

        return true;
    }

    public function publishProduct($id, array $fields): bool
    {
        $product = $this->getProductDao()->get($id);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        if (!empty($fields['userId']) && $product['userId'] !== $fields['userId']) {
            //操作失败,无权限关闭此作品
            throw ProductException::PRODUCT_FORBIDDEN_CLOSE_USER_PRODUCT();
        }

        if ($product['status'] === BizEnum::PRODUCT_STATUS_PUBLISHED) {
            throw ProductException::PRODUCT_CLOSED();
        }

        $this->getProductDao()->update($id, [
            'status' => BizEnum::PRODUCT_STATUS_PUBLISHED
        ]);
        $this->getLogService()->info('product', 'publish', "发布作品《{$product['title']}》,作品ID:{$id}", [
            'userId' => $fields['userId'],
            'productId' => $id,
            'currentIp' => $fields['currentIp'] ?? '127.0.0.1'
        ]);

        return true;
    }

    /**
     * 更新封面
     * @param int $id
     * @param string $cover
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function setProductCover(int $id, string $cover)
    {
        return $this->getProductDao()->update($id, ['cover' => $cover]);
    }

    /**
     * 添加热点
     * @param array $fields
     * @return mixed
     */
    public function makeHotPoint(array $fields)
    {
        if (!ArrayToolkit::requireds($fields, ['uuid', 'productId', 'sceneId', 'userId'])) {
            throw  CommonBizException::ERROR_PARAMETER();
        }
        $product = $this->getProductDao()->get($fields['productId']);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }
//        $scene = $this->getScene($fields['sceneId']);
        $fields = ArrayToolkit::parts($fields, [
            'id',
            'uuid',
            'productId',
            'sceneId',
            'userId',
            'icon',
            'iconType',
            'iconSize',
            'type',
            'title',
            'titleFontSize',
            'titleFontColor',
            'imgUrls',
            'toSceneId',
            'link',
            'linkTarget',
            'videoUrl',
            'content',
            'iconMarkerParams',
            'iconTitleMarkerParams'
        ]);
        empty($fields['toSceneId']) && $fields['toSceneId'] = 0;
        empty($fields['iconTitleMarkerParams']) && $fields['iconTitleMarkerParams'] = [];
        $hotpoint = $this->getHotpointByUUID($fields['uuid']);
        return $hotpoint ? $this->getHotPointDao()->update($hotpoint['id'], array_merge($hotpoint, $fields)) : $this->getHotPointDao()->create($fields);
    }

    public function deleteHotPoint(int $id)
    {
        return $this->getHotPointDao()->delete($id);
    }

    public function deleteHotPointBySceneId(int $sceneId)
    {
        return $this->getHotPointDao()->deleteBySceneId($sceneId);
    }

    public function getHotpointByUUID(string $uuid)
    {
        return $this->getHotPointDao()->getByUUID($uuid);
    }

    public function searchHotPoints(array $conditions, array $orderBys, $start, $limit = null, $columns = [])
    {
        return $this->getHotPointDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function getProductTour(int $productId)
    {
        return $this->getTourDao()->getByProductId($productId);
    }

    public function setProductTour(int $productId, array $fields)
    {
        $product = $this->getProductDao()->get($productId);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }
        $tour = $this->getProductTour($productId);
        $fields = ArrayToolkit::parts($fields, ['title', 'startImg', 'endImg', 'loopPlay', 'endToStart', 'txtPosition', 'txtSize']);
        v::notEmpty()->setName('标题')->check($fields['title'] ?? '');
        v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('循环播放导览')->check($fields['loopPlay']);
        v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('播放结束后回到起始场景')->check($fields['endToStart']);
        v::in(['top', 'bottom'])->setName('文字位置')->check($fields['txtPosition']);
        v::in([12, 14, 16, 18, 20, 22, 24])->setName('文字字号')->check($fields['txtSize']);
        if (!empty($tour)) {
            return $this->getTourDao()->update($tour['id'], $fields);
        }

        $fields['productId'] = $productId;

        return $this->getTourDao()->create($fields);
    }

    public function getProductTourNodes(int $productId)
    {
        $nodes = $this->getTourNodeDao()->getAllByProductId($productId);
        $sceneIds = ArrayToolkit::column($nodes, 'sceneId');
        $scenes = $this->getSceneDao()->getAll(['ids' => $sceneIds], null, ['id', 'title']);
        $sceneGroupList = ArrayToolkit::index($scenes, 'id');
        foreach ($nodes as &$node) {
            $node['sceneTitle'] = $sceneGroupList[$node['sceneId']]['title'] ?? '';
        }
        return $nodes;
    }

    public function setProductTourNodes(int $productId, array $fields)
    {
        $product = $this->getProductDao()->get($productId);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

//        if (empty($fields['nodes'])) {
//            throw ProductException::SET_PRODUCT_TOUR_NODES_EMPTY_NODES();
//        }

        $nodes = $fields['nodes'];
        $this->beginTransaction();
        try {
            $tour = $this->getProductTour($productId);
            if (empty($tour)) {
                $tour = $this->getTourDao()->create([
                    'productId' => $productId,
                    'title' => $product['title'],
                    'startImg' => $product['cover'],
                    'endImg' => $product['cover'],
                ]);
            }
            $existTourNodes = ArrayToolkit::index($this->getProductTourNodes($productId), 'idx');
            if (empty($nodes)) {
                count($existTourNodes) > 0 && $this->getTourNodeDao()->batchDelete(['productId' => $productId]);
            } else {
                $addNodes = [];
                $updNodes = [];
                foreach ($nodes as $node) {
                    if (!isset($node['index'])) {
                        continue;
                    }
                    if (isset($existTourNodes[$node['index']])) {
                        $id = $existTourNodes[$node['index']]['id'];
                        $updNodes[$id] = [
                            'productId' => $productId,
                            'sceneId' => $node['sceneId'],
                            'tourId' => $tour['id'],
                            'idx' => $node['index'],
                            'code' => $node['code'],
                            'position' => $node['position'],
                            'waitTime' => $node['waitTime'],
                            'content' => $node['content'],
                            'voice' => $node['voice'],
                        ];
                    } else {
                        $addNodes[] = [
                            'productId' => $productId,
                            'sceneId' => $node['sceneId'],
                            'tourId' => $tour['id'],
                            'idx' => $node['index'],
                            'code' => $node['code'],
                            'position' => $node['position'],
                            'waitTime' => $node['waitTime'],
                            'content' => $node['content'],
                            'voice' => $node['voice'],
                        ];
                    }
                }

                $this->getTourNodeDao()->batchCreate($addNodes);
                if (!empty($updNodes)) {
                    $updNodeIds = array_keys($updNodes);
                    $this->getTourNodeDao()->batchUpdate($updNodeIds, $updNodes);
                }
            }


            $this->commit();
            $count = count($nodes);
            $this->getLogService()->info('product', 'set-product-tour-nodes', "作品(ID={$product['id']})成功登记{$count}个导游节点", [
                'nodes' => $nodes,
                'userId' => $fields['userId'] ?? 1,
                'currentIp' => $fields['currentIp'] ?? '',
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            $this->getLogService()->info('product', 'set-product-tour-nodes', "作品(ID={$product['id']}),导游节点登记失败,{$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 删除导游节点
     *
     * @param int $id
     * @return mixed|bool
     */
    public function deleteProductTour(int $id)
    {
        $tour = $this->getTourNodeDao()->get($id);
        if (empty($tour)) {
            throw ProductException::NOT_FOUND_PRODUCT_TOUR_NODE();
        }
        $this->beginTransaction();
        try {
            $this->getTourNodeDao()->delete($id);
            $afterNodes = $this->getTourNodeDao()->getAll(['productId' => $tour['productId'], 'idx_GT' => $tour['idx']], ['idx' => 'ASC'], ['id', 'idx']);
            foreach ($afterNodes as $afterNode) {
                $diff = $afterNode['idx'] - 1;
                $this->getTourNodeDao()->update($afterNode['id'], ['idx' => $diff]);
            }
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function savePlaneGraphMarkers(PlaneGraphMarkersDto $dto)
    {
        $product = $this->getProductDao()->get($dto->productId);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        $fields = [
            'productId' => $product['id'],
            'imgUrl' => $dto->imgUrl,
            'type' => $dto->type,
            'markers' => $dto->markers,
            'center' => $dto->center,
            'rotation' => $dto->rotation,
        ];
        if (!empty( $dto->gisParam)) {
            $fields['gisParam'] = $dto->gisParam;
        }

        $planeGraph = $this->getPlaneGraphDao()->getByProductId($product['id']);
        if (empty($planeGraph)) {
            if ('default' === $dto->type && empty($dto->imgUrl)) {
                throw ProductException::CREATE_PRODUCT_PLANE_GRAPH_IMG_PATH_EMPTY();
            }
            return $this->getPlaneGraphDao()->create($fields);
        }

        return $this->getPlaneGraphDao()->update($planeGraph['id'], $fields);
    }

    /**
     * 添加电子地图场景点位(已弃用)
     *
     * @param PlaneGraphMarkersDto $dto
     * @return mixed
     * @deprecated
     */
    public function oldSavePlaneGraphMarkers(PlaneGraphMarkersDto $dto)
    {
        $product = $this->getProductDao()->get($dto->productId);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        // 为空,清除节点
        if (empty($dto->markers)) {
            $this->getPlaneGraphDao()->batchDelete(['productId' => $product['id']]);
        }

        $existMarkers = $this->getPlaneGraphDao()->getAll(['productId' => $product['id']]);
        $existMarkerSceneIds = ArrayToolkit::column($existMarkers, 'sceneId');
        $newMarkerScenedIds = ArrayToolkit::column($dto->markers, 'sceneId');
        // 在新标记中存在,  但在已存在标记中不存在的场景ID
        $addMarkerSceneIds = array_diff($newMarkerScenedIds, $existMarkerSceneIds);
        //已存在标记中存在, 但在新标记中不存在的场景ID
        $removeMarkerSceneIds = array_diff($existMarkerSceneIds, $newMarkerScenedIds);
        //已存在的和新提供的交集就是需要更新的
        $updateMarkerSceneIds = array_intersect($existMarkerSceneIds, $newMarkerScenedIds);
        $this->beginTransaction();
        try {
            if (count($addMarkerSceneIds) > 0) {
                $addMarkers = array_filter($dto->markers, function (&$marker) use ($addMarkerSceneIds) {
                    return in_array($marker['sceneId'], $addMarkerSceneIds);
                });
                $addRows = array_map(function ($marker) use ($product) {
                    return [
                        'productId' => $product['id'],
                        'sceneId' => $marker['sceneId'],
                        'position' => $marker['position'],
                        'deg' => $marker['deg'],
                    ];
                }, $addMarkers);
                $this->getPlaneGraphDao()->batchCreate($addRows);
            }

            if (count($removeMarkerSceneIds) > 0) {
                $this->getPlaneGraphDao()->batchDelete(['productId' => $product['id'], 'sceneIds' => $removeMarkerSceneIds]);
            }

            if (count($updateMarkerSceneIds) > 0) {
                $existMarkerSceneGroupList = ArrayToolkit::index($existMarkers, 'sceneId');
                $newMarkerSceneGroupList = ArrayToolkit::index($dto->markers, 'sceneId');
                $updMarkers = [];
                foreach ($updateMarkerSceneIds as $scenedId) {
                    $updMarker = $newMarkerSceneGroupList[$scenedId];
                    $existUpdMarker = $existMarkerSceneGroupList[$scenedId];
                    $updMarkers[$existUpdMarker['id']] = [
                        'position' => $updMarker['position'],
                        'deg' => $updMarker['deg'],
                    ];
                }

                if (!empty($updMarkers)) {
                    $updMarkerIds = array_keys($updMarkers);
                    $this->getPlaneGraphDao()->batchUpdate($updMarkerIds, $updMarkers);
                }
            }
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            $this->getLogService()->info('product', 'create-plane-graph-markers', "作品(ID={$product['id']}),电子地图场景点位保存失败,{$e->getMessage()}");
            throw $e;
        }

    }

    /**
     * 获取电子地图配置
     * @param int $productId
     * @return array|array[]|null
     */
    public function getPlaneGraphByProductId(int $productId)
    {
        $planeGraph = $this->getPlaneGraphDao()->getByProductId($productId);
        if (empty($planeGraph)) {
            return null;
        }

        if ($planeGraph['type'] === 'default') {
            $planeGraph['imgUrlFull'] = AssetHelper::getUploadUrl($planeGraph['imgUrl']);
        } else {
            $planeGraph['imgUrlFull'] = '';
        }

        $sceneIds = ArrayToolkit::column($planeGraph['markers'], 'sceneId');
        $scenes = $this->getSceneDao()->getAll(['ids' => $sceneIds], null, ['id', 'title']);
        $scenes = ArrayToolkit::index($scenes, 'id');
        empty($planeGraph['center']) && $planeGraph['center'] = null;
        foreach ($planeGraph['markers'] as $key => &$marker) {
            if (isset($scenes[$marker['sceneId']])) {
                $marker['sceneTitle'] = $scenes[$marker['sceneId']]['title'];
                $marker['title'] = $marker['sceneTitle'];
            }
            if (!isset($marker['image'])) {
                $marker['image'] = AssetHelper::getAssetUrl('images/default/map-scene-marker.png');
                $marker['size'] = 25;
            }
        }

        return $planeGraph;
    }

    /**
     * 获取作品的电子地图场景点位明细(已弃用)
     *
     * @param int $productId
     * @return array[]
     * @deprecated
     */
    public function getOldPlaneGraphMarkersByProductId(int $productId)
    {
        $markers = $this->getPlaneGraphDao()->getAll(['productId' => $productId]);
        $sceneIds = ArrayToolkit::column($markers, 'sceneId');
        $scenes = $this->getSceneDao()->getAll(['ids' => $sceneIds], null, ['id', 'title']);
        $scenes = ArrayToolkit::index($scenes, 'id');
        foreach ($markers as &$marker) {
            $marker['sceneTitle'] = $scenes[$marker['sceneId']]['title'] ?? '---';
        }

        return $markers;
    }


    /**
     * 设置品牌logo (允许为空)
     *
     * @param int $productId
     * @param string $logo
     * @param string $brandWebsite
     * @param string $logoPosition
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function setProductLogo(int $productId, string $logo, string $brandWebsite = '', string $logoPosition = 'left_top')
    {
        $product = $this->getProductDao()->get($productId);
        if (empty($product)) {
            throw  ProductException::NOT_FOUND_PRODUCT();
        }

        return $this->getProductDao()->update($productId, [
            'logo' => $logo,
            'brandWebsite' => $brandWebsite,
            'logoPosition' => empty($logoPosition) ? 'left_top' : $logoPosition
        ]);
    }

    /**
     * 获取作品logo
     * @param int $productId
     * @return mixed
     */
    public function geProductLogo(int $productId)
    {
        $product = $this->getProductDao()->get($productId);
        if (empty($product)) {
            throw  ProductException::NOT_FOUND_PRODUCT();
        }

        return [
            'logo' => $product['logo'],
            'logo_full' => AssetHelper::getUploadUrl($product['logo']),
            'link_url' => $product['brandWebsite'],
            'logo_position' => $product['logoPosition'],
        ];
    }

    /**
     * 作品配置
     *
     * @param ProductConfigDto $dto
     * @return mixed
     */
    public function setProductConfig(ProductConfigDto $dto)
    {
        $product = $this->getProductDao()->get($dto->productId);
        if (empty($product)) {
            throw  ProductException::NOT_FOUND_PRODUCT();
        }

        if (empty($dto->key)) {
            throw  CommonBizException::ERROR_PARAMETER();
        }

        $setting = $this->getSettingDao()->getByProductIdAndKey($dto->productId, $dto->key);

        if (!empty($setting)) {
            return $this->getSettingDao()->update($setting['id'], [
                'userId' => $dto->userId,
                'name' => $dto->key,
                'val' => $dto->values
            ]);
        }

        return $this->getSettingDao()->create([
            'productId' => $dto->productId,
            'userId' => $dto->userId,
            'name' => $dto->key,
            'val' => $dto->values
        ]);
    }

    /**
     * 获取所有的产品配置
     * @param int $productId
     * @return array
     */
    public function getProductConfigs(int $productId): array
    {
        $configs = $this->getSettingDao()->findByProductId($productId);
        $items = [];
        foreach ($configs as $config) {
            $config = $this->mapProductConfig($config);
            $items[$config['name']] = $config['val'];
        }

        return $items;
    }

    /**
     * 获取作品单个配置
     *
     * @param int $productId
     * @param string $key
     * @return mixed|null|array
     */
    public function getProductConfig(int $productId, string $key)
    {
        $config = $this->getSettingDao()->getByProductIdAndKey($productId, $key);

        return $this->mapProductConfig($config);
    }

    protected function mapProductConfig(?array $config): ?array
    {
        if (empty($config)) {
            return null;
        }

        if (!empty($config['val']['img'])) {
            $config['val']['img_full'] = AssetHelper::getUploadUrl($config['val']['img']);
        }

        if (!empty($config['val']['pc_img'])) {
            $config['val']['pc_img_full'] = AssetHelper::getUploadUrl($config['val']['pc_img']);
        }

        if (!empty($config['val']['mobile_img'])) {
            $config['val']['mobile_img_full'] = AssetHelper::getUploadUrl($config['val']['mobile_img']);
        }

        if (!empty($config['val']['background_img'])) {
            $config['val']['background_img_full'] = AssetHelper::getUploadUrl($config['val']['background_img']);
        }

        if (!empty($config['val']['pc_ad_img'])) {
            $config['val']['pc_ad_img_full'] = AssetHelper::getUploadUrl($config['val']['pc_ad_img']);
        }

        if (!empty($config['val']['mobile_ad_img'])) {
            $config['val']['mobile_ad_img_full'] = AssetHelper::getUploadUrl($config['val']['mobile_ad_img']);
        }

        if (!empty($config['val']['bottom_mask_img'])) {
            $config['val']['bottom_mask_img_full'] = AssetHelper::getUploadUrl($config['val']['bottom_mask_img']);
        }

        return $config;
    }


    public function deleteProduct($id, $userId)
    {
        $product = $this->getProductById($id);
        if (intval($product['userId']) !== intval($userId)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }
        $this->beginTransaction();
        try {
            $this->getProductDao()->delete($id);
            $this->getSceneDao()->batchDelete(['productId' => $id]);
            $this->getProductTagDao()->batchDelete(['productId' => $id]);
            $this->commit();
            $this->getLogService()->info('product', 'delete', '成功删除作品', $product);
            foreach ($product['scenes'] as $scene) {
                Client::send('delete-scene', ['product' => $product, 'scene' => $scene, 'delProduct' => true]);
            }
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            $this->getLogService()->info('product', 'delete', '删除作品失败,' . $e->getMessage(), $product);
            return false;
        }
    }

    /**
     * 作品类型列表
     *
     * @return array[]
     */
    public function typeList()
    {
        $items = BizEnum::getProductTypeItems();

        return [
            [
                'key' => BizEnum::PRODUCT_TYPE_PICTURES,
                'label' => $items[BizEnum::PRODUCT_TYPE_PICTURES],
                'logo' => AssetHelper::getAssetUrl('images/default/vr_pictures.png'),
                'desc' => '适用于静态场景展示，呈现自然风光或建筑景观，提供静谧观赏体验。'
            ],
            [
                'key' => BizEnum::PRODUCT_TYPE_VIDEOS,
                'label' => $items[BizEnum::PRODUCT_TYPE_VIDEOS],
                'logo' => AssetHelper::getAssetUrl('images/default/vr_videos.png'),
                'desc' => '适合动态场景展示，可用于旅游、教育等，传递沉浸式的故事体验。'
            ],
            [
                'key' => BizEnum::PRODUCT_TYPE_3D_RING,
                'label' => $items[BizEnum::PRODUCT_TYPE_3D_RING],
                'logo' => AssetHelper::getAssetUrl('images/default/vr_3d_ring.png'),
                'desc' => '用于交互体验，适配游戏、模拟场景，提供自由探索和互动。'
            ]
        ];
    }


    protected function generateUserProductTags($userId, $productId, $recommendTagIds, $customTags)
    {
        $tagItems = [];
        $newTags = [];
        $recommendTags = [];
        $recommendTagIds = array_unique($recommendTagIds);
        if (!empty($recommendTagIds)) {
            $recommendTags = $this->getTagService()->searchTags(['ids' => $recommendTagIds], [], 0, PHP_INT_MAX, ['id', 'name', 'userId']);
        }
        // 已经存在的自定义标签
        $oldCustomTags = $this->getTagService()->searchTags([
            'userId' => $userId,
            'names' => $customTags
        ], [], 0, PHP_INT_MAX, ['id', 'name', 'userId']);

        // 添加推荐标签
        foreach ($recommendTags as $recommendTag) {
            $tagItems[] = [
                'productId' => $productId,
                'userId' => $userId,
                'tagType' => 'system',
                'tagId' => $recommendTag['id'],
                'tagName' => $recommendTag['name'],
            ];
        }

        // 用户所有的标签
        $excludeTags = array_merge($recommendTags, $oldCustomTags);
        foreach ($customTags as $customTag) {
            $exist = false;
            foreach ($excludeTags as $excludeTag) {
                if ($excludeTag['name'] === $customTag) {
                    $exist = true;
                    $tagItems[] = [
                        'productId' => $productId,
                        'userId' => $userId,
                        'tagType' => 'custom',
                        'tagId' => $excludeTag['id'],
                        'tagName' => $excludeTag['name'],
                    ];
                    break;
                }
            }

            if ($exist) {
                continue;
            }

            $newTags[] = [
                'userId' => $userId,
                'type' => 'custom',
                'name' => $customTag
            ];


        }

        if (!empty($newTags)) {
            $this->getTagService()->batchCreateTags($newTags);
            $newCustomTags = $this->getTagService()->searchTags([
                'userId' => $userId,
                'type' => 'custom',
                'names' => ArrayToolkit::column($newTags, 'name')
            ], [], 0, PHP_INT_MAX, ['id', 'name']);
            foreach ($newCustomTags as $customTag) {
                $tagItems[] = [
                    'productId' => $productId,
                    'userId' => $userId,
                    'tagType' => 'custom',
                    'tagId' => $customTag['id'],
                    'tagName' => $customTag['name'],
                ];
            }
        }

        $this->getProductTagDao()->batchDelete(['productId' => $productId]);

        return $this->getProductTagDao()->batchCreate($tagItems);
    }

    protected function filterPanoramaByPathAndThumbPath($path, $thumbPath = ''): array
    {
        if (strpos($path, 'http') !== false) {
            return [$path, $thumbPath];
        }

        $file = AssetHelper::uploadPath($path);
        $thumbFile = AssetHelper::uploadPath($thumbPath);
        if (!is_file($file) || !is_file($thumbFile)) {
            return [null, null];
        }

        return [$file, $thumbFile];
    }

    /**
     * 验证是否有权限访问作品
     *
     * @param string $admin
     * @param string $password
     * @return mixed
     */
    public function validateViewPwd(string $admin, string $password)
    {
        $productId = ((int)$admin - 5) / 2;
        $product = $this->getProductDao()->get($productId);
        if (empty($product)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        if (empty($product['password'])) {
            return true;
        }

        // 提交的密码必须md5
        $md5 = md5($product['password'] . '_' . $admin);
        if ($md5 === $password) {
            return true;
        }

        throw ProductException::PRODUCT_VIEW_PWD_CHECK_ERROR();
    }


    protected function validateFields($fields)
    {
        v::notEmpty()->setName('作品名称')->check($fields['title'] ?? '');
        if (isset($fields['catalogId'])) {
            v::callback(function ($catalogId) {
                if ($catalogId === '') {
                    return false;
                }

                $catalogId = (int)$catalogId;
                if ($catalogId === 0) {
                    return true;
                }

                return !empty($this->getCatalogService()->getProductCatalogById($catalogId));
            })->setTemplate('作品分类不存在')->check($fields['catalogId']);
        }

        if (isset($fields['type'])) {
            v::in(array_keys(BizEnum::getProductTypeItems()))->setName('作品分类')->check($fields['type']);
        }

        if (isset($fields['password'])) {
            v::stringVal()->setName('访问密码')->check($fields['password']);
        }

        $fields = ArrayToolkit::parts($fields, ['title', 'catalogId', 'cover', 'type', 'scenes', 'recommendTagIds', 'customTags', 'remark', 'recommend', 'useIntro', 'anonymousShow', 'userId', 'status', 'address', 'password', 'hotPoints']);

        return $fields;
    }


    /**
     * 获取分享地址
     * http://localhost:3010/view/186
     * @param int $productId
     * @return string
     */
    public function makeShareUrl(int $productId): array
    {
        $product = $this->getProductDao()->get($productId);
        $uri = config('app.design_site_url', null);
        if (empty($product) || empty($uri)) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        $cover = !empty($product['cover']) ? AssetHelper::getUploadUrl($product['cover']) : AssetHelper::getUploadUrl($product['logo']);
        $productShareConfig = $this->getProductConfig($productId, 'product_share');
        if (empty($productShareConfig) || !$productShareConfig['val']['time_limit']) {
            return [
                'url' => sprintf("%s/vr/%s", rtrim($uri, '/'), $product['code']),
                'cover' => $cover
            ];
        }

        $expired_time = sprintf("%s|%s", $productId, time() + (float)$productShareConfig['val']['expired_time'] * 3600);
        $tokenHandler = new ExpiredTimeToToken(md5(config('app.name'), true));
        $token = $tokenHandler->generateToken($expired_time);

        return [
            'url' => sprintf("%s/share/%s", rtrim($uri, '/'), $token),
            'cover' => $cover
        ];
    }

    public function checkShareToken(string $token)
    {
        $tokenHandler = new ExpiredTimeToToken(md5(config('app.name'), true));
        $rawBody = $tokenHandler->decodeToken($token);
        $params = explode('|', $rawBody);
        if (empty($params[0]) || empty($params[1])) {
            throw ProductException::NOT_FOUND_PRODUCT();
        }

        $expiredTime = intval($params[1]);
        if (time() > $expiredTime) {
            throw ProductException::PRODUCT_SHARE_EXPIRED();
        }

        return intval($params[0]);
    }

    public function increaseViewCount(int $id): int
    {
        // 并发控制：如果有大量并发请求访问相同的记录，可以考虑使用数据库锁或其他机制来防止视图计数更新冲突。
        // 缓存: 为了减少数据库压力，可以将视图计数缓存在内存中（例如使用Redis），并定期将计数写回数据库。
        return $this->getProductDao()->increment($id, 'clickCount');
    }

    public function increaseLikeCount(int $id): int
    {
        return $this->getProductDao()->increment($id, 'likeCount');
    }

    /**
     * @return ProductTagDao
     */
    protected function getProductTagDao()
    {
        return $this->createDao('Product:ProductTagDao');
    }

    /**
     * @return ProductDao
     */
    protected function getProductDao()
    {
        return $this->createDao('Product:ProductDao');
    }

    /**
     * @return ProductSceneDao
     */
    protected function getSceneDao()
    {
        return $this->createDao('Product:ProductSceneDao');
    }

    /**
     * @return ProductHotPointDao
     */
    protected function getHotPointDao()
    {
        return $this->createDao('Product:ProductHotPointDao');
    }

    /**
     * @return ProductTourDao
     */
    protected function getTourDao()
    {
        return $this->createDao('Product:ProductTourDao');
    }

    /**
     * @return ProductTourNodeDao
     */
    protected function getTourNodeDao()
    {
        return $this->createDao('Product:ProductTourNodeDao');
    }

    /**
     * @return ProductPlaneGraphDao
     */
    protected function getPlaneGraphDao()
    {
        return $this->createDao('Product:ProductPlaneGraphDao');
    }

    /**
     * 作品设置表
     * @return ProductSettingDao
     */
    protected function getSettingDao()
    {
        return $this->createDao('Product:ProductSettingDao');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->createService('Setting:SettingService');
    }

    /**
     * @return ProductCatalogService
     */
    protected function getCatalogService()
    {
        return $this->createService('Product:ProductCatalogService');
    }

    /**
     * @return SystemLogService
     */
    protected function getLogService()
    {
        return $this->createService('SystemLog:SystemLogService');
    }

    /**
     * @return ProductTagService
     */
    protected function getTagService()
    {
        return $this->createService('Product:ProductTagService');
    }

    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->createService('VIP:VIPService');
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->createService('Attachment:AttachmentService');
    }
}
