<?php

namespace app\api\v1\controller;

use app\api\BaseController;
use app\api\filters\ProductFilter;
use app\api\filters\ProductSceneFilter;
use app\api\filters\ProductTourFilter;
use CoreW\Business\Attachment\Dto\FileUploadDto;
use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Business\BizEnum;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Product\Dto\PlaneGraphMarkersDto;
use CoreW\Business\Product\Dto\ProductConfigDto;
use CoreW\Business\Product\Exception\ProductException;
use CoreW\Business\Product\Service\ProductCatalogService;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Business\Product\Service\ProductTagService;
use CoreW\Business\User\Exception\UserException;
use CoreW\Event\Event;
use support\Request;
use support\Response;
use support\utils\GisUtil;
use support\utils\Paginator;

class ProductController extends BaseController
{
    /**
     * @param Request $request
     * @return \support\Response
     */
    public function getHotpointTypeItems(Request $request): \support\Response
    {
        $vrTypeDict = BizEnum::getVrHotpointTypeItems();
        if ((int)$this->getVIPInfo()->getRole() === BizEnum::VIP_ROLE_PERSON) {
            unset($vrTypeDict[BizEnum::VR_HOTPOINT_TYPE_IOT]);
        }

        return $this->createSuccessJsonResponse(BizEnum::dictToList($vrTypeDict, 'value', 'label'));
    }

    /**
     * 上传场景资源
     *
     * @param Request $request
     * @return void
     */
    public function sceneUpload(Request $request)
    {
        $key = $request->post('key', 'scene');
        $group = 'scene';
        $file = $request->file($key);
        if (!$this->checkAspectRatio($file->getPathname())) {
            return $this->createErrorJsonResponse('请上传2:1的全景图片', null, CommonBizException::PARAMETER_TYPE_ERROR, 400);
        }

        $dto = new FileUploadDto([
            'file' => $file,
            'type' => 'single_file_upload',
            'group' => $group,
            'userId' => $this->getVIPInfo()->getId(),
            'client' => 'frontend',
            'isSaveThumbImage' => true,
            'thumbImageOptions' => [
                'box' => [512, 512]
            ]
        ]);

        $result = $this->getAttachmentService()->uploadFile($dto);

        return $this->createSuccessJsonResponse($result);
    }

    /**
     * 场景上传配置
     *
     * @param Request $request
     * @return \support\Response
     */
    public function sceneUploadConfig(Request $request)
    {

        return $this->createSuccessJsonResponse($this->getProductService()->getSceneUploadConfig());
    }

    /**
     * 获取作品类型明细
     *
     * @param Request $request
     * @return \support\Response
     */
    public function typeList(Request $request)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->typeList());
    }

    /**
     * 获取作品分类明细
     *
     * @param Request $request
     * @return \support\Response
     */
    public function catalogList(Request $request)
    {
        return $this->createSuccessJsonResponse($this->getProductCatalogService()->getRootList([], ['id', 'code', 'name', 'remark', 'icon', 'cover']));
    }

    /**
     * 获取推荐标签明细
     * @param Request $request
     * @return void
     */
    public function tagList(Request $request)
    {
        $conditions = ['type' => 'system'];
        $catalogId = $request->get('catalog_id', null);
        if ($catalogId) {
            $conditions['catalog_id'] = $catalogId;
        }

        return $this->createSuccessJsonResponse($this->getTagService()->searchTags($conditions, ['id' => 'DESC'], 0, PHP_INT_MAX));
    }


    public function sceneList(Request $request)
    {
        $conditions = $request->get();
        $scenes = $this->getProductService()->searchScenes($conditions, ['number' => 'ASC'], 0, PHP_INT_MAX);
        $filter = new ProductSceneFilter();
        $filter->filters($scenes);

        return $this->createSuccessJsonResponse($scenes);
    }

    /**
     * 查看场景详情
     *
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function showScene(Request $request, $id)
    {
        $scene = $this->getProductService()->getScene((int)$id);
        $filter = new ProductSceneFilter();
        $filter->filter($scene);

        return $this->createSuccessJsonResponse($scene);
    }

    /**
     * 作品添加场景
     *
     * @param Request $request
     * @param $id
     * @return \support\Response|void
     */
    public function addScene(Request $request, $id)
    {
        $key = $request->post('key', 'scene');
        $group = 'scene';
        $file = $request->file($key);
        if (!$this->checkAspectRatio($file->getPathname())) {
            return $this->createErrorJsonResponse('请上传2:1的全景图片', null, CommonBizException::PARAMETER_TYPE_ERROR, 400);
        }

        $dto = new FileUploadDto([
            'file' => $file,
            'type' => 'single_file_upload',
            'group' => $group,
            'userId' => $this->getVIPInfo()->getId(),
            'client' => 'frontend',
            'isSaveThumbImage' => true,
            'thumbImageOptions' => [
                'box' => [512, 512]
            ]
        ]);

        $scene = $this->getProductService()->addScene($id, $dto, [
            'userId' => $this->getVIPInfo()->getId(),
            'index' => $request->post('index', 0)
        ]);

        $filter = new ProductSceneFilter(ProductSceneFilter::SIMPLE_MODE);
        $filter->filter($scene);

        return $this->createSuccessJsonResponse($scene);
    }

    public function sortScene(Request $request, $id)
    {
        if ($this->getProductService()->updateSceneSortNum($id, $request->post())) {
            return $this->createSuccessJsonResponse(null, '更新成功');
        }

        return $this->createErrorJsonResponse('更新失败');
    }

    public function updateScene(Request $request, $id)
    {
        if ($this->getProductService()->updateScene($id, $request->post())) {
            return $this->createSuccessJsonResponse(null, '更新成功');
        }

        return $this->createErrorJsonResponse('更新失败');
    }

    /**
     * 删除场景
     *
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function removeScene(Request $request, $id)
    {
        if ($this->getProductService()->deleteScene($id)) {
            return $this->createSuccessJsonResponse(null, '删除成功');
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    /**
     * 首页-获取作品列表明细
     *
     * @param Request $request
     * @return \support\Response
     */
    public function list(Request $request)
    {
        return $this->searchList($request);
    }

    public function myList(Request $request)
    {
        return $this->searchList($request, ProductFilter::SIMPLE_MODE, (int)$this->getVIPInfo()->getId());
    }

    /**
     * 获取作品详情
     *
     * @param $id
     * @return \support\Response
     */
    public function getProduct(Request $request, $id): \support\Response
    {
        $product = is_numeric($id) ? $this->getProductService()->getProductById($id) : $this->getProductService()->getProductByCode($id);
        $filter = new ProductFilter(ProductFilter::SIMPLE_MODE);
        $filter->filter($product);

        return $this->createSuccessJsonResponse($product);
    }

    /**
     * 作品预览-获取作品详情
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function getProductViewInfo(Request $request, $id): \support\Response
    {
        $product = is_string($id) ? $this->getProductService()->getProductByCode($id) : $this->getProductService()->getProductById($id);
        $configs = $this->getProductService()->getProductConfigs((int)$product['id']);
        if ($this->isGuestVIPUser()) {
            // 游客默认中如果作品不允许匿名，则抛出异常
            if ($product['anonymousShow'] != 1) {
                throw CommonBizException::RESOURCE_FORBIDDEN();
            }

            if (!empty($configs['product_share'])) {
                if ($configs['product_share']['status'] == 1 && $configs['product_share']['time_limit'] == 1) {
                    throw CommonBizException::NOTFOUND_RESOURCE();
                }
            }

            $this->dispatchEvent('vr.product.view', new Event($product));
        } else {
            $isCreatorView = $request->get('creator_view', 0); // 是否是创作者预览
            if ((!$isCreatorView && $this->getVIPInfo()->getId() != $product['userId'])) {
                $this->dispatchEvent('vr.product.view', new Event($product));
            }
        }

        $product = $this->getProductService()->getProductByCode($product['code']);
        $product['configs'] = $configs;
        $filter = new ProductFilter(ProductFilter::SIMPLE_MODE);
        $filter->filter($product);

        return $this->createSuccessJsonResponse($product);
    }

    /**
     * 创建作品
     *
     * @param Request $request
     * @return \support\Response
     * @throws \Throwable
     */
    public function createProduct(Request $request)
    {
        $data = $request->post();
        $data['userId'] = $this->getVIPInfo()->getId();
        $data['currentIp'] = $request->getRealIp();
        if ($productId = $this->getProductService()->createProduct($data)) {
            return $this->createSuccessJsonResponse(['id' => $productId], '创建成功');
        }

        return $this->createErrorJsonResponse([], '创建失败了，请联系管理员');
    }


    /**
     * 更新作品
     *
     * @param Request $request
     * @param $id
     * @return \support\Response
     * @throws \Throwable
     */
    public function updateProduct(Request $request, $id)
    {
        $data = $request->post();
        $data['userId'] = $this->getVIPInfo()->getId();
        $data['currentIp'] = $request->getRealIp();
        if ($productId = $this->getProductService()->updateProduct($id, $data)) {
            return $this->createSuccessJsonResponse(['id' => $productId], '更新成功');
        }

        return $this->createErrorJsonResponse([], '更新失败了，请联系管理员');
    }

    public function likeProduct(Request $request, $id)
    {
        if ($this->getProductService()->increaseLikeCount($id)) {
            return $this->createSuccessJsonResponse(['id' => $id], '点赞成功');
        }

        return $this->createErrorJsonResponse([], '点赞失败了，请联系管理员');
    }

    public function closeProduct(Request $request, $id): \support\Response
    {
        if ($this->getProductService()->closeProduct($id, [
            'userId' => $this->getVIPInfo()->getId(),
            'currentIp' => $request->getRealIp()
        ])) {
            return $this->createSuccessJsonResponse(['id' => $id], '关闭成功');
        }

        return $this->createErrorJsonResponse([], '关闭失败了，请联系管理员');
    }

    public function publishProduct(Request $request, $id): \support\Response
    {
        if ($this->getProductService()->publishProduct($id, [
            'userId' => $this->getVIPInfo()->getId(),
            'currentIp' => $request->getRealIp()
        ])) {
            return $this->createSuccessJsonResponse(['id' => $id], '发布成功');
        }

        return $this->createErrorJsonResponse([], '发布失败了，请联系管理员');
    }

    /**
     * 删除作品
     *
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function deleteProduct(Request $request, $id)
    {
        if ($this->getProductService()->deleteProduct($id, $this->getVIPInfo()->getId())) {
            return $this->createSuccessJsonResponse(null, '删除成功');
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    public function getSceneHotPoints(Request $request, $id): Response
    {
        $conditions = [
            'sceneId' => $id
        ];
        $points = $this->getProductService()->searchHotPoints($conditions, ['createdTime' => 'DESC'], 0);
        $useAiHuman = $request->get('useAiHuman', false);
        if ($useAiHuman) {
            foreach ($points as &$point) {
                !isset($point['content']) && $point['content'] = '';
                !isset($point['iconMarkerParams']['data']['content']) && $point['iconMarkerParams']['data']['content'] = '';
                if ('polygon' === $point['iconType'] && !empty($point['videoUrl'])) {
                    $iconMarker = $point['iconMarkerParams'];
                    if (!empty($iconMarker['polygon']) && count($iconMarker['polygon']) >= 4) {
                        $poiItems = array_slice($iconMarker['polygon'], 0, 4);
//                        $size = GisUtil::calculateWidthHeight($poiItems);
                        $point['iconMarkerParams'] = [
                            'id' => $iconMarker['id'],
                            'videoLayer' => $point['videoUrl'],
                            'autoplay' => false,
                            'position' => GisUtil::getVideoLayerPositionByPolygonCorners($poiItems),
//                            'size' => $size,
                            'style' => [
                                'cursor' => 'pointer'
                            ],
                            'chromaKey' => [
                                'enabled' => true,
                                'color' => '#014EF2',
                                'similarity' => 0.1
                            ],
                            'tooltip' => $iconMarker['tooltip'] ?? 'Play / Pause',
                            'data' => $iconMarker['data']
                        ];
                    }
                }
            }
        }

        return $this->createSuccessJsonResponse($points);
    }

    public function addHotPoint(Request $request)
    {
        $fields = $request->post();
        $fields['userId'] = $this->getVIPInfo()->getId();
        $hotpoint = $this->getProductService()->makeHotPoint($fields);
        if ($hotpoint) {
            return $this->createSuccessJsonResponse($hotpoint, '添加成功');
        }

        return $this->createErrorJsonResponse('添加失败');
    }

    public function delHotPoint(Request $request, $id)
    {
        if ($this->getProductService()->deleteHotPoint($id)) {
            return $this->createSuccessJsonResponse(null, '删除成功');
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    public function getHotPoint(Request $request, $uuid)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->getHotpointByUUID($uuid));
    }

    public function delSceneHotPoints(Request $request, $id)
    {
        if ($this->getProductService()->deleteHotPointBySceneId($id)) {
            return $this->createSuccessJsonResponse(null, '删除成功');
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    /**
     * 一键导游全局设置
     *
     * @param Request $request
     * @param int|string $id 作品ID
     * @return void
     */
    public function tourGlobalSet(Request $request, $id)
    {
        $fields = $request->post();
        if ($this->getProductService()->setProductTour($id, $fields)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse();
    }

    /**
     * 导游节点记录
     *
     * @param Request $request
     * @return void
     */
    public function tourNodesSet(Request $request, $id)
    {
        $nodes = $request->post('nodes', []);
        $fields = [
            'userId' => $this->getVIPInfo()->getId(),
            'currentIp' => $request->getRealIp(),
            'nodes' => $nodes
        ];
        if ($this->getProductService()->setProductTourNodes($id, $fields)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse();
    }

    /**
     * 获取作品的导游配置
     *
     * @param Request $request
     * @param int|string $id 作品ID
     * @return void
     */
    public function getProductTour(Request $request, $id)
    {
        $tour = $this->getProductService()->getProductTour($id);
        if (!empty($tour)) {
            $filter = new ProductTourFilter();
            $filter->filter($tour);
        }

        return $this->createSuccessJsonResponse($tour);
    }

    /**
     * 获取作品的导游节点
     *
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function getTourNodes(Request $request, $id)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->getProductTourNodes($id));
    }

    public function delProductTour(Request $request, $id)
    {
        if ($this->getProductService()->deleteProductTour($id)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    public function createPlaneGraphMarkers(Request $request, $id)
    {
        $dto = new PlaneGraphMarkersDto([
            'productId' => $id,
            'userId' => $this->getVIPInfo()->getId(),
            'currentIp' => $request->getRealIp(),
            'markers' => $request->post('markers', []),
            'type' => $request->post('type', 'default'),
            'imgUrl' => $request->post('imgUrl', ''),
            'gisParam' => $request->post('gisParam', []),
            'rotation' => $request->post('rotation', ''),
            'center' => $request->post('mapCenterPoint', ['x' => 0, 'y' => 0])
        ]);
        if ($this->getProductService()->savePlaneGraphMarkers($dto)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse();
    }

    public function getProductPlaneGraph(Request $request, $id)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->getPlaneGraphByProductId($id));
    }

    public function setLogo(Request $request, $id)
    {
        $logo = $request->post('logo', '');
        $logoPosition = $request->post('logo_position', 'left_top');
        $linkUrl = $request->post('link_url', '');
        if ($this->getProductService()->setProductLogo($id, $logo, $linkUrl, $logoPosition)) {
            return $this->createSuccessJsonResponse([], '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function getLogo(Request $request, $id)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->geProductLogo($id));
    }

    public function setConfig(Request $request, $id)
    {
        $dto = new ProductConfigDto([
            'productId' => $id,
            'userId' => $this->getVIPInfo()->getId(),
            'key' => $request->post('key'),
            'values' => $request->post('values', [])
        ]);

        if ($this->getProductService()->setProductConfig($dto)) {
            return $this->createSuccessJsonResponse([], '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function getConfig($id, $key)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->getProductConfig($id, $key));
    }

    public function validateViewPassword(Request $request)
    {
        $admin = $request->post('admin', '');
        $pwd = $request->post('pwd', '');

        return $this->createSuccessJsonResponse($this->getProductService()->validateViewPwd($admin, $pwd));
    }

    public function makeShareUrl(Request $request, $id)
    {
        return $this->createSuccessJsonResponse($this->getProductService()->makeShareUrl($id));
    }

    public function checkShareToken(Request $request)
    {
        $productId = $this->getProductService()->checkShareToken($request->post('token', ''));

        return $this->getProductViewInfo($request, $productId);
    }

    protected function checkAspectRatio($imagePath, $aspectRatioWidth = 2, $aspectRatioHeight = 1)
    {
        // 获取图片尺寸
        $imageSize = getimagesize($imagePath);
        // 检查是否成功获取图片尺寸
        if ($imageSize !== false) {
            $width = $imageSize[0]; // 图片宽度
            $height = $imageSize[1]; // 图片高度
            // 计算宽高比
            $aspectRatio = $width / $height;
            // 判断是否符合特定宽高比
            if ($aspectRatio === $aspectRatioWidth / $aspectRatioHeight) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * @param Request $request
     * @param int|null $userId
     * @return \support\Response
     */
    protected function searchList(Request $request, $filterType = ProductFilter::PUBLIC_MODE, int $userId = null)
    {
        $conditions = $request->get();
        if ($userId) {
            $conditions['userId'] = $userId;
        }
        if (!empty($conditions['date_span']) && is_array($conditions['date_span']) && 2 === count($conditions['date_span'])) {
            $conditions['startTime'] = strtotime($conditions['date_span'][0]);
            $conditions['endTime'] = strtotime($conditions['date_span'][1]) + 86400 - 1;
            unset($conditions['date_span']);
        }
        $total = $this->getProductService()->countProducts($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);
        $products = $this->getProductService()->searchProducts($conditions, ['id' => 'DESC'], $offset, $limit);
        $filter = new ProductFilter($filterType, false);
        $filter->filters($products);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => $products,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * @return ProductService
     */
    protected function getProductService()
    {
        return $this->getBiz()->service('Product:ProductService');
    }

    /**
     * @return ProductCatalogService
     */
    protected function getProductCatalogService()
    {
        return $this->getBiz()->service('Product:ProductCatalogService');
    }

    /**
     * @return ProductTagService
     */
    protected function getTagService()
    {
        return $this->getBiz()->service('Product:ProductTagService');
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->createService('Attachment:AttachmentService');
    }
}
