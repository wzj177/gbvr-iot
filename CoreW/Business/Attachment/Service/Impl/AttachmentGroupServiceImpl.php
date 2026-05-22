<?php


namespace CoreW\Business\Attachment\Service\Impl;


use CoreW\Business\Attachment\Dao\AttachmentDao;
use CoreW\Business\Attachment\Dao\AttachmentGroupDao;
use CoreW\Business\Attachment\Exception\AttachmentException;
use CoreW\Business\Attachment\Service\AttachmentGroupService;
use CoreW\Business\BaseService;
use CoreW\Exception\NotFoundException;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v;
use support\utils\ArrayToolkit;
use support\utils\TreeHelper;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class AttachmentGroupServiceImpl extends BaseService implements AttachmentGroupService
{
    /**
     * 获取指定编码集合的分组
     *
     * @param array $codes
     * @return array eg: array('default' => [], 'vip' => [])
     */
    public function findAllByCodes(array $codes)
    {
        $groups = $this->getAttachmentGroupDao()->getAllByCodes($codes);


        return ArrayToolkit::index($groups, 'code');
    }

    public function getAttachmentGroupById($id)
    {
        $group = $this->getAttachmentGroupDao()->get($id);
        if (empty($group)) {
            throw  new NotFoundException("附件分组不存在");
        }

        return $group;
    }

    public function getAttachmentGroupByCode($code)
    {
        $group = $this->getAttachmentGroupDao()->getByCode($code);
        if (empty($group)) {
            throw  new NotFoundException("附件分组不存在");
        }

        return $group;
    }

    public function countAttachmentGroups(array $conditions)
    {
        return $this->getAttachmentGroupDao()->count($conditions);
    }

    public function searchAttachmentGroups(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getAttachmentGroupDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    /**
     * 分组树
     *
     * @param array $conditions
     * @param string $mode
     * @return array
     */
    public function getTree(array $conditions, $mode = 'infinite_limit')
    {
        $items = $this->getAttachmentGroupDao()->getAll($conditions, ['sort' => 'ASC'], ['id', 'code', 'title', 'parentId', 'isDefault', 'level']);
        if ('infinite_limit' === $mode) {
            return TreeHelper::referenceDeliveryTree($items, 'id', 'parentId');
        }

        return TreeHelper::spanningTree($items, 0, 'parentId');
    }

    /**
     * @param array $fields
     * @return array|boolean|integer|null
     */
    public function createAttachmentGroup(array $fields)
    {
        $fields = $this->validateFields($fields);
        v::callback(function ($value) {
            return $this->validateTitleNotExist($value);
        })->setTemplate('分组标题已存在')->check($fields['title']);
        if (!empty($fields['code'])) {
            v::callback(function ($value) {
                return $this->validateCodeNotExist($value);
            })->setTemplate('分组编码已存在')->check($fields['code']);
        } else {
            $fields['code'] = $this->generateCode();
        }

        if (!isset($fields['sort']) || !is_numeric($fields['sort'])) {
            $fields['sort'] = 10;
        }

        $fields['level'] = 1;
        $fields['parentId'] = $fields['parentId'] ?? 0;
        if ($fields['parentId']) {
            $parent_group = $this->getAttachmentGroupDao()->get($fields['parentId']);
            if (!empty($parent_group)) {
                $fields['level'] = $parent_group['level'] + 1;
                $fields['code'] = $this->generateCode($fields['parentId']);
            }
        }

        return $this->getAttachmentGroupDao()->create($fields);
    }

    public function updateAttachmentGroup($id, array $fields)
    {
        $group = $this->getAttachmentGroupById($id);
        $fields = $this->validateFields($fields);

        if (!empty($fields['title']) && $group['title'] !== $fields['title']) {
            v::callback(function ($value) {
                return $this->validateTitleNotExist($value);
            })->setTemplate('分组标题已存在')->check($fields['title']);
        }

        if (!empty($fields['code']) && $group['code'] !== $fields['code']) {
            v::callback(function ($value) {
                return $this->validateCodeNotExist($value);
            })->setTemplate('分组编码已存在')->check($fields['code']);
        }

        if (isset($fields['sort']) && !is_numeric($fields['sort'])) {
            $fields['sort'] = 10;
        }

        if (isset($fields['parentId']) && (int)$fields['parentId'] !== (int)$group['parentId']) {
            $parent_group = $this->getAttachmentDao()->get($fields['parentId']);
            if (!empty($parent_group)) {
                $fields['level'] = $parent_group['level'] + 1;
            }
        }

        return $this->getAttachmentGroupDao()->update($id, $fields);
    }

    public function deleteAttachmentGroupById($id)
    {
        $group = $this->getAttachmentGroupById($id);
        if ((int)$group['isDefault'] === 1) {
            throw AttachmentException::ATTACHMENT_GROUP_DENY_DELETE();
        }

        if ($this->getAttachmentDao()->count(['group' => $group['code']]) > 0) {
            throw AttachmentException::ATTACHMENT_GROUP_HAS_CHILD_DENY_DELETE();
        }

        return $this->getAttachmentGroupDao()->delete($id);
    }

    public function batchDelete($ids)
    {
        if (is_string($ids)) {
            $ids = explode('|', $ids);
        }

        if (empty($ids)) {
            throw AttachmentException::BAD_REQUEST_BATCH_DELETE_IDS();
        }

        // TODO: 简单处理（数据量很小，后期数据大换）
        $result = true;
        foreach ($ids as $id) {
            $result = $this->deleteAttachmentGroupById($id);
        }

        return $result;
    }

    /**
     * @param $fields
     * @return array
     */
    protected function validateFields($fields)
    {
        $rules = [
            'title' => v::notEmpty()->setName('分组标题'),
        ];
        if (isset($fields['parentId'])) {
            $rules['parentId'] = v::intVal()->setName('上级ID');
        }
        if (isset($fields['isDefault'])) {
            $rules['isDefault'] = v::intVal()->setName('默认分组');
        }
        if (isset($fields['code'])) {
            $rules['code'] = v::stringVal()->setName('分组编码');
        }
        if (isset($fields['sort'])) {
            $rules['sort'] = v::numericVal()->setName('排序');
        }
        return v::input($fields, $rules);
    }

    protected function validateCodeNotExist($value)
    {
        $group = $this->getAttachmentGroupDao()->getByCode($value);

        return empty($group);
    }

    protected function validateTitleNotExist($value)
    {
        $group = $this->getAttachmentGroupDao()->getByTitle($value);

        return empty($group);
    }

    protected function generateCode($pid = 0)
    {
        $len = strlen($pid);
        $fix = date('YmdHis');
        $max = 4;
        if ($len > $max) {
            return $fix . $pid;
        }

        return $fix . str_pad('0', $max - $len, STR_PAD_LEFT) . $pid;
    }

    /**
     * @return AttachmentGroupDao
     */
    protected function getAttachmentGroupDao()
    {
        return $this->createDao('Attachment:AttachmentGroupDao');
    }

    /**
     * @return AttachmentDao
     */
    protected function getAttachmentDao()
    {
        return $this->createDao('Attachment:AttachmentDao');
    }
}