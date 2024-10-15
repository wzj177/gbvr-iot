<?php


namespace CoreW\Business\Attachment\Service;


interface AttachmentGroupService
{
    public function getAttachmentGroupById($id);

    public function getAttachmentGroupByCode($code);

    public function countAttachmentGroups(array $conditions);

    public function searchAttachmentGroups(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function createAttachmentGroup(array $fields);

    public function updateAttachmentGroup($id, array $fields);

    public function deleteAttachmentGroupById($id);

    public function batchDelete($ids);

    /**
     * 分组树
     *
     * @param array $conditions
     * @param string $mode
     * @return mixed
     */
    public function getTree(array $conditions, $mode = 'infinite_limit');

    /**
     * 获取指定编码集合的分组
     *
     * @param array $codes
     * @return array eg: array('default' => [], 'vip' => [])
     */
    public function findAllByCodes(array $codes);
}