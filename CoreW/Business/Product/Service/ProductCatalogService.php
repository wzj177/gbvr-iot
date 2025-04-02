<?php


namespace CoreW\Business\Product\Service;


interface ProductCatalogService
{
    /**
     * @param array $conditions
     * @return int
     */
    public function countProductCatalog(array $conditions): int;

    /**
     * @param array $conditions
     * @param array $orderBys
     * @param int $start
     * @param int $limit
     * @param array $columns
     * @return array
     */
    public function searchProductCatalogs(array $conditions, array $orderBys, int $start, int $limit, array $columns = []): array;

    /**
     * @param array $fields
     * @return bool|array|null
     */
    public function createProductCatalog(array $fields);

    /**
     * @param int $id
     * @param array $fields
     * @return bool|array|null
     */
    public function updateProductCatalog(int $id, array $fields);

    /**
     * 修改排序
     * @param int $id
     * @param int $sort
     * @return int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function updateCatalogSort(int $id, int $sort);

    /**
     * 修改状态
     *
     * @param int $id
     * @param int $status
     * @return mixed
     */
    public function updateCatalogStatus(int $id, int $status);

    /**
     * @param int $id
     * @return array|null
     */
    public function getProductCatalogById(int $id): ?array;

    /**
     * @param int $id
     * @return bool
     */
    public function deleteProductCatalogById(int $id): bool;

    /**
     * 获取分类通过分类名称
     *
     * @param string $name
     * @return array|null
     */
    public function getProductCatalogByName(string $name): ?array;

    /**
     * 获取分类通过分类编码
     *
     * @param string $code
     * @return array|null
     */
    public function getProductCatalogByCode(string $code): ?array;

    /**
     * 获取分类树
     *
     * @param array $conditions
     * @param string $type
     * @return array
     */
    public function getTree(array $conditions, $type = 'infinite_limit'): array;

    /**
     * @param array $fields
     * @return bool
     */
    public function batchUpdateStatus(array $fields) :bool;

    /**
     * 批量删除分类
     *
     * @param array $ids
     * @return bool
     */
    public function batchUpdateDeleteByIds(array $ids) :bool;

    /**
     * 获取顶级分类
     *
     * @param array $conditions
     * @return array[]
     */
    public function getRootList(array $conditions = []);
}