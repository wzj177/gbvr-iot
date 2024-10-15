<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\ProductTagFilter;
use CoreW\Business\Product\Service\ProductTagService;
use support\Request;
use support\utils\Paginator;

class ProductTagController extends BaseController
{
    public function index(Request $request)
    {
        $conditions = [];
        $fields = $request->get();
        if (!empty($fields['keyword'])) {
            $conditions['keyword'] = $fields['keyword'];
        }

        $total = $this->getProductTagService()->countTag($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);
        $sort = $this->getSort($request);
        $sort['id'] = 'DESC';
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);
        $items = $this->getProductTagService()->searchTags($conditions, $sort, $paginator->getOffsetCount(), $paginator->getPerPageCount());
        $filter = new ProductTagFilter();
        $filter->filters($items);

        return $this->createSuccessJsonResponse([
            'list' => $items,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    public function tagOptions(Request $request)
    {
        $tags = $this->getProductTagService()->searchTags([], ['id' => 'DESC'], 0, PHP_INT_MAX, ['id', 'name']);

        return $this->createSuccessJsonResponse($tags);
    }

    public function addTags(Request $request)
    {
        $content = $request->post('content', '');

        if ($this->getProductTagService()->addTags($this->getCurrentUser()->getId(), $content)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse();
    }

    public function destroy(Request $request, $id)
    {
        if ($this->getProductTagService()->deleteTagById(intval($id))) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('删除失败');

    }

    public function batchDestroy(Request $request)
    {
        if ($this->getProductTagService()->batchDeleteByIds($request->post('ids', []), $this->getCurrentUser()->getId(), $request->getRealIp())) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse("批量删除失败");
    }

    /**
     * @return ProductTagService
     */
    protected function getProductTagService()
    {
        return $this->createService('Product:ProductTagService');
    }

}
