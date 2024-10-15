<?php

namespace CoreW\Business\Product\Service;

use CoreW\Business\Attachment\Dto\FileUploadDto;
use CoreW\Business\Product\Dto\PlaneGraphMarkersDto;
use CoreW\Business\Product\Dto\ProductConfigDto;

interface ProductService
{
    /**
     * 获取作品详情
     *
     * @param $id
     * @return array|null
     */
    public function getProductById($id): ?array;

    /**
     * 获取作品详情
     *
     * @param $id
     * @return array|null
     */
    public function getProductByCode(string $code): ?array;

    /**
     * 获取作品统计数
     *
     * @param array $conditions
     * @return int
     */
    public function countProducts(array $conditions);

    /**
     *
     * 获取作品明细
     * @param array $conditions
     * @param array $orderBys
     * @param $start
     * @param $limit
     * @param $columns
     * @return mixed
     */
    public function searchProducts(array $conditions, array $orderBys, $start, $limit, $columns = []);

    /**
     * 创建作品
     *
     * @param array $fields
     * @return true|int
     * @throws \Throwable
     */
    public function createProduct(array $fields);

    /**
     * 更新作品
     *
     * @param $id
     * @param array $fields
     * @return mixed
     */
    public function updateProduct($id, array $fields);

    /**
     * 会员(或者系统)关闭作品
     * @param $id
     * @param array $fields
     * @return bool
     * @throws \CoreW\Dao\DaoException
     */
    public function closeProduct($id, array $fields): bool;

    /**
     * 会员(或者系统)发布作品
     * @param $id
     * @param array $fields
     * @return bool
     * @throws \CoreW\Dao\DaoException
     */
    public function publishProduct($id, array $fields): bool;

    /**
     * 删除作品
     * @param $id
     * @param $userId
     * @return mixed
     */
    public function deleteProduct($id, $userId);


    /**
     * 作品类型列表
     * @return array[]
     */
    public function typeList();


    /**
     * 场景上传配置参数
     *
     * @return array
     */
    public function getSceneUploadConfig();

    /**
     * 更新 场景
     *
     * @param int $productId
     * @param int $index
     * @param array $fields
     * @return false|int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function updateSceneByProductAndIndex(int $productId, int $index, array $fields);

    /**
     * 添加场景
     *
     * @param int $productId
     * @param FileUploadDto $uploadDto
     * @param array $fields
     * @return mixed
     */
    public function addScene(int $productId, FileUploadDto $uploadDto, array $fields);


    /**
     * 更新场景
     *
     * @param int $sceneId
     * @param array $fields
     * @return mixed
     */
    public function updateScene(int $sceneId, array $fields);


    /**
     * 删除场景
     *
     * @param int $sceneId
     * @return mixed
     */
    public function deleteScene(int $sceneId);


    /**
     * 修改场景排序
     *
     * @param int $sceneId
     * @param array $fields
     * @return mixed
     */
    public function updateSceneSortNum(int $sceneId, array $fields);

    /**
     * @param int $sceneId
     * @return mixed|array|null
     */
    public function getScene(int $sceneId);


    /**
     * 获取场景列表
     * @param array $conditions
     * @param array $orderBys
     * @param $start
     * @param $limit
     * @param $columns
     * @return mixed|array|array[]
     */
    public function searchScenes(array $conditions, array $orderBys, $start, $limit, $columns = []);


    /**
     * 更新封面
     * @param int $id
     * @param string $cover
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function setProductCover(int $id, string $cover);


    /**
     * 添加热点
     *
     * @param array $fields
     * @return mixed
     */
    public function makeHotPoint(array $fields);

    /**
     * 删除热点
     *
     * @param int $id
     * @return mixed
     */
    public function deleteHotPoint(int $id);

    /**
     * 删除场景下所有热点
     *
     * @param int $sceneId
     * @return mixed
     */
    public function deleteHotPointBySceneId(int $sceneId);

    /**
     * 通过uuid 获取热点
     * @param string $uuid
     * @return mixed
     */
    public function getHotpointByUUID(string $uuid);

    /**
     * 获取热点明细列表
     *
     * @param array $conditions
     * @param array $orderBys
     * @param $start
     * @param $limit
     * @param $columns
     * @return mixed
     */
    public function searchHotPoints(array $conditions, array $orderBys, $start, $limit = null, $columns = []);

    /**
     * 获取作品导游配置
     * @param int $productId
     * @return mixed
     */
    public function getProductTour(int $productId);


    /**
     * 一健导游全局设置
     * @param int $productId
     * @param array $fields
     * @return mixed
     */
    public function setProductTour(int $productId, array $fields);

    /**
     * 获取作品导游节点
     *
     * @param int $productId
     * @return mixed
     */
    public function getProductTourNodes(int $productId);


    /**
     * 设置作品导游节点
     * @param int $productId
     * @param array $fields
     * @return mixed
     */
    public function setProductTourNodes(int $productId, array $fields);


    /**
     * 删除导游节点
     *
     * @param int $id |bool
     * @return mixed
     */
    public function deleteProductTour(int $id);


    /**
     * 添加电子地图场景点位
     *
     * @param PlaneGraphMarkersDto $dto
     * @return mixed|bool
     */
    public function savePlaneGraphMarkers(PlaneGraphMarkersDto $dto);

    /**
     * 获取作品的电子地图场景点位明细
     *
     * @param int $productId
     * @return array[]
     */
    public function getPlaneGraphByProductId(int $productId);


    /**
     * 设置作品logo
     *
     * @param int $productId
     * @param string $logo
     * @param string $brandWebsite
     * @param string $logoPosition
     * @return mixed
     */
    public function setProductLogo(int $productId, string $logo, string $brandWebsite = '', string $logoPosition = 'leftTop');

    /**
     * 获取作品logo
     * @param int $productId
     * @return mixed
     */
    public function geProductLogo(int $productId);


    /**
     * 作品配置
     *
     * @param ProductConfigDto $dto
     * @return mixed
     */
    public function setProductConfig(ProductConfigDto $dto);

    /**
     * 获取作品全部配置
     *
     * @param int $productId
     * @return array
     */
    public function getProductConfigs(int $productId): array;

    /**
     * 获取作品配置
     *
     * @param int $productId
     * @param string $key
     * @return mixed
     */
    public function getProductConfig(int $productId, string $key);

    /**
     * 验证是否有权限访问作品
     *
     * @param string $admin
     * @param string $password
     * @return mixed
     */
    public function validateViewPwd(string $admin, string $password);

    /**
     * 获取分享地址
     * http://localhost:3010/view/186
     * @param int $productId
     * @return array
     */
    public function makeShareUrl(int $productId): array;

    /**
     * 解析分享token
     * @param string $token
     * @return mixed
     */
    public function checkShareToken(string $token);

    /**
     * 增加浏览量
     * @param int $id
     * @return int
     */
    public function increaseViewCount(int $id): int;

    /**
     * 增加点赞量
     * @param int $id
     * @return int
     */
    public function increaseLikeCount(int $id): int;
}
