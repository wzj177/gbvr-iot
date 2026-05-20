<?php


namespace CoreW\Business\Product\Service;


interface ProductTagService
{
    /**
     * 计算总数
     *
     * @param array $conditions
     * @return int
     */
    public function countTag(array $conditions) : int;

    /**
     * 获取明细
     *
     * @param array $conditions
     * @param array $orderBys
     * @param int $start
     * @param int $limit
     * @param array $columns
     * @return array
     */
    public function searchTags(array $conditions, array $orderBys, int $start, int $limit, array $columns = []) : array;

    /**
     * 生成tag
     *
     * @param int|null|string $userId
     * @param string $tags
     * @param string $type
     * @return bool
     */
    public function addTags(?int $userId, string $tags, $type = 'system') : bool;

    /**
     * 删除tag
     *
     * @param int $id
     * @return bool
     */
    public function deleteTagById(int $id) : bool;

    /**
     * 批量删除
     *
     * @param array $ids
     * @param int|null $userId
     * @param string|null $userIp
     * @return bool
     */
    public function batchDeleteByIds(array $ids, ?int $userId, ?string $userIp) : bool;

    public function batchCreateTags(array $tags);

    public function deleteCustomTagsByUserId($userId);
}