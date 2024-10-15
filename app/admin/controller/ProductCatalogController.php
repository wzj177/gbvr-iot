<?php

namespace app\admin\controller;

use app\admin\filters\ProductCatalogFilter;
use CoreW\Business\BizEnum;
use Respect\Validation\Validator as v;
use support\Request;
use support\utils\Paginator;

use CoreW\Business\Product\Service\ProductCatalogService;
use app\admin\BaseController;

class ProductCatalogController extends BaseController
{
    public function tree(Request $request)
    {
        $conditions = [];
        $fields = $request->get();
        if (!empty($fields['keyword'])) {
            $conditions['keyword'] = $fields['keyword'];
        }

        if (isset($fields['status']) && is_numeric($fields['status'])) {
            $conditions['status'] = $fields['status'];
        }

        if (isset($fields['parentId'])) {
            $conditions['parentId'] = intval($fields['parentId']);
        }

        $mode = $fields['mode'] ?? 'infinite_limit';
        if (!in_array($mode, ['infinite_limit', 'tree_options'])) {
            return $this->createSuccessJsonResponse([]);
        }



        return $this->createSuccessJsonResponse($this->getProductCatalogService()->getTree($conditions, $mode));
    }

    public function index(Request $request)
    {
        $conditions = [];
        $fields = $request->get();
        if (!empty($fields['keyword'])) {
            $conditions['keyword'] = $fields['keyword'];
        }

        $total = $this->getProductCatalogService()->countProductCatalog($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);
        $sort = $this->getSort($request);
        $sort['id'] = 'DESC';
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);
        $items = $this->getProductCatalogService()->searchProductCatalogs($conditions, $sort, $paginator->getOffsetCount(), $paginator->getPerPageCount());
        $filter = new ProductCatalogFilter();
        $filter->filters($items);

        return $this->createSuccessJsonResponse([
            'list' => $items,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    public function store(Request $request)
    {
        $fields = $request->post();
        $fields['currentUserId'] = $this->getCurrentUser()->getId();
        $fields['currentUserIp'] = $request->getRealIp();
        $this->getProductCatalogService()->createProductCatalog($fields);
        return $this->createSuccessJsonResponse();
    }

    public function show(Request $request, $id)
    {
        $item = $this->getProductCatalogService()->getProductCatalogById($id);
        $filter = new ProductCatalogFilter();
        $filter->filter($item);

        return $this->createSuccessJsonResponse($item);
    }

    public function update(Request $request, $id)
    {
        $fields = $request->post();
        $fields['currentUserId'] = $this->getCurrentUser()->getId();
        $fields['currentUserIp'] = $request->getRealIp();
        $this->getProductCatalogService()->updateProductCatalog($id, $fields);

        return $this->createSuccessJsonResponse();
    }

    public function updateSort(Request $request, $id)
    {
        $sort = $request->post('sort', null);
        v::intVal()->check($sort);

        if ($this->getProductCatalogService()->updateCatalogSort($id, $sort)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('修改排序失败');

    }

    public function updateStatus(Request $request, $id)
    {
        $status = $request->post('status', null);
        v::intVal()->check($status);

        if ($this->getProductCatalogService()->updateCatalogStatus($id, $status)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('修改状态失败');

    }

    public function batchUpdateStatus(Request $request)
    {
        $fields = v::input($request->post(), [
            'ids' => v::arrayVal()->addRule(v::notEmpty()->setTemplate('分类id参数错误'))->setName('分类id参数错误'),
            'status' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('状态'),
        ]);
        if ($this->getProductCatalogService()->batchUpdateStatus($fields)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('修改失败');
    }

    public function batchDestroy(Request $request)
    {
        $fields = v::input($request->post(), [
            'ids' => v::arrayVal()->addRule(v::notEmpty()->setTemplate('分类id参数错误'))->setName('分类id参数错误')
        ]);

        if ($this->getProductCatalogService()->batchUpdateDeleteByIds($fields['ids'])) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('修改失败');
    }

    public function destroy(Request $request, $id)
    {
        if ($this->getProductCatalogService()->deleteProductCatalogById($id)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse("删除失败");
    }


    /**
     * @return ProductCatalogService
     */
    protected function getProductCatalogService()
    {
        return $this->createService('Product:ProductCatalogService');
    }

}
