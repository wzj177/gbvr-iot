<?php


namespace app\admin\controller;

use app\admin\filters\AttachmentGroupFilter;
use CoreW\Business\Attachment\Service\AttachmentGroupService;
use support\Request;
use app\admin\BaseController;

class AttachmentGroupController extends BaseController
{
    public function index(Request $request)
    {
        $conditions = [];
        $fields = $request->get();
        if (!empty($fields['keyword'])) {
            $conditions['keyword'] = $fields['keyword'];
        }
        if (!empty($fields['code'])) {
            $conditions['code'] = $fields['code'];
        }

        $groups = $this->getAttachmentGroupService()->searchAttachmentGroups($conditions, ['id' => 'ASC'], 0, PHP_INT_MAX);
        $filter = new AttachmentGroupFilter();
        $filter->filters($groups);

        return $this->createSuccessJsonResponse($groups);
    }

    /**
     * @param Request $request
     * @return \support\Response
     */
    public function trees(Request $request)
    {
        $conditions = [];
        $fields = $request->get();
        if (!empty($fields['parentId'])) {
            $conditions['parentId'] = intval($fields['parentId']);
        }

        if (isset($fields['level']) && is_numeric($fields['level'])) {
            $conditions['level'] = intval($fields['level']);
        }

        if (isset($fields['level_le']) && is_numeric($fields['level_le'])) {
            $conditions['level_le'] = intval($fields['level_le']);
        }

        $mode = $fields['mode'] ?? 'infinite_limit';
        if (in_array($mode, ['infinite_limit', 'tree_options'])) {
            $items = $this->getAttachmentGroupService()->getTree($conditions, $mode);
        } else {
            $items = $this->getAttachmentGroupService()->searchAttachmentGroups($conditions, ['parentId' => 'ASC', 'sort' => 'ASC'], 0, PHP_INT_MAX);
            $filter = new AttachmentGroupFilter();
            $filter->filters($items);
        }


        return $this->createSuccessJsonResponse($items);
    }

    public function store(Request $request)
    {
        $this->getAttachmentGroupService()->createAttachmentGroup($request->post());

        return $this->createSuccessJsonResponse();
    }

    public function show(Request $request, $id)
    {
        // 防止枚举：if $group['userId'] !== $currentUser['id'] throw new NotAllowException('无权限')
        $group = $this->getAttachmentGroupService()->getAttachmentGroupById($id);
        $filter = new AttachmentGroupFilter();
        $filter->filter($group);

        return $this->createSuccessJsonResponse($group);
    }

    public function update(Request $request, $id)
    {
        $this->getAttachmentGroupService()->updateAttachmentGroup($id, $request->post());
        return $this->createSuccessJsonResponse();
    }

    public function destroy(Request $request, $id)
    {

        $this->getAttachmentGroupService()->deleteAttachmentGroupById($id);

        return $this->createSuccessJsonResponse();
    }

    public function destroyMore(Request $request)
    {
        $ids = $request->post('ids', []);

        if (!$this->getAttachmentGroupService()->batchDelete($ids)) {
            return $this->createSuccessJsonResponse(null, '删除失败');
        }

        return $this->createSuccessJsonResponse(null, '删除成功');
    }

    /**
     * @return AttachmentGroupService
     */
    protected function getAttachmentGroupService()
    {
        return $this->createService('Attachment:AttachmentGroupService');
    }
}