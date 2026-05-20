<?php


namespace CoreW\Business\Product\Service\Impl;


use CoreW\Business\BaseService;
use CoreW\Business\BizEnum;
use CoreW\Business\Product\Dao\ProductCatalogDao;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\Product\Dao\ProductCatalogTagDao;
use CoreW\Business\Product\Dao\TagDao;
use CoreW\Business\Product\Exception\ProductException;
use CoreW\Business\Product\Service\ProductCatalogService;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Exception\NotFoundException;
use Illuminate\Support\Arr;
use Respect\Validation\Validator as v;
use support\utils\ArrayToolkit;
use support\utils\StringToolkit;
use support\utils\TreeHelper;

class ProductCatalogServiceImpl extends BaseService implements ProductCatalogService
{
    /**
     * 获取顶级分类
     *
     * @param array $conditions
     * @return array[]
     */
    public function getRootList(array $conditions = [], array $columns = [])
    {
        $conditions['parentId'] = 0;

        return $this->searchProductCatalogs($conditions, ['sort' => 'ASC', 'id' => 'DESC'], 0, PHP_INT_MAX, $columns);
    }

    /**
     * 批量删除分类
     *
     * @param array $ids
     * @return bool
     */
    public function batchUpdateDeleteByIds(array $ids) : bool
    {
        if (empty($ids)) {
            return false;
        }
        // 过滤存在子集的分类id
        $childItems = $this->searchProductCatalogs(['parentIds' => $ids], [], 0, PHP_INT_MAX, ['id', 'parentId']);
        $childGroupItems = ArrayToolkit::group($childItems, 'parentId');
        $removeIds = [];
        foreach ($ids as $id) {
            if (!empty($childGroupItems[$id])) {
                continue;
            }
            $removeIds[] = $id;
        }

        // TODO：针对绑定的分类，就不删除或者可以删除且作品侧展示为未分类

        return $this->getProductCatalogDao()->batchDelete(['ids' => $removeIds]);
    }

    public function batchUpdateStatus(array $fields) : bool
    {
        $fields = ArrayToolkit::parts($fields, ['ids', 'status']);
        if (empty($fields['ids']) || !is_array($fields['ids']) || !isset($fields['status'])) {
            return false;
        }

        $updRows = [];
        foreach ($fields['ids'] as $id) {
            $updRows[$id] = ['status' => $fields['status']];
        }

        $this->getProductCatalogDao()->batchUpdate($fields['ids'], $updRows);

        return true;
    }

    /**
     * @param array $conditions
     * @return int
     */
    public function countProductCatalog(array $conditions) : int
    {
        return $this->getProductCatalogDao->count($conditions);
    }

    /**
     * @param array $conditions
     * @param array $orderBys
     * @param int $start
     * @param int $limit
     * @param array $columns
     * @return array
     */
    public function searchProductCatalogs(array $conditions, array $orderBys, int $start, int $limit, array $columns = []) : array
    {
        return $this->getProductCatalogDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    /**
     * @param array $conditions
     * @param string $type
     * @return array
     */
    public function getTree(array $conditions, $type = 'infinite_limit') : array
    {
        $orderBys = $type === 'infinite_limit' ? ['sort' => 'ASC', 'id' => 'DESC'] : [
            'parentId' => 'ASC',
            'id'       => 'DESC',
        ];
        $items = array_map(function ($item) {
            return $this->mapCatalog($item);

        }, $this->getProductCatalogDao()->getAll($conditions, $orderBys, [
            'id',
            'name',
            'path',
            'parentId',
            'icon',
            'code',
            'status',
            'sort',
            'createdTime',
        ]));

        if ($type === 'infinite_limit') {
            return TreeHelper::referenceDeliveryTree($items, 'id', 'parentId');
        }


        $root = [
            'id'          => 0,
            'path'        => '',
            'name'        => '顶级分类',
            'treeName'    => '顶级分类',
            'parentId'    => 0,
            'sort'        => 0,
            'icon'        => '',
            'code'        => 'root',
            'status'      => 1,
            'createdTime' => time(),
        ];

        $results = TreeHelper::spanningTree($items, 0, 'parentId');
        array_unshift($results, $root);

        return $results;
    }

    protected function mapCatalog($catalog)
    {
        $separator = "　　";
        $deep = count(array_filter(explode('.', rtrim($catalog['path'], '.'))));
        $catalog['treeName'] = $deep == 0 ? $catalog['name'] : str_repeat($separator, $deep - 1) . '├' . $catalog['name'];
        $catalog['statusText'] = BizEnum::getEnableOrDisableItems($catalog['status']);
        $catalog['createdTime'] = $catalog['createdTime'];

        return $catalog;
    }

    /**
     * @param array $fields
     * @return bool|array|null
     */
    public function createProductCatalog(array $fields)
    {
        $nameExistRule = v::callback(function ($value) {
            return empty($this->getProductCatalogByName($value)) ? true : false;
        })->setTemplate('分类已存在');
        $codeExistRule = v::callback(function ($value) {
            if (empty($value)) {
                return true;
            }
            return empty($this->getProductCatalogByCode($value)) ? true : false;
        })->setTemplate('分类编码已存在');
        !isset($fields['sort']) && $fields['sort'] = 0;
        $fields = v::input($fields, [
            'name'          => v::notEmpty()->addRule($nameExistRule)->setName('分类名称'),
            'code'          => $codeExistRule,
            'parentId'      => v::callback(function ($value) {
                if ($value === '' || $value === null) {
                    return true;
                }

                return is_numeric($value);
            })->setTemplate('父级id必须是数字'),
            'status'        => v::intVal()->setName('状态'),
            'remark'        => v::stringVal()->setName('备注'),
            'icon'          => v::stringVal()->setName('图标'),
            'cover'         => v::stringVal()->setName('封面图'),
            'recommendTags' => v::arrayVal()->setName('推荐标签'),
            'currentUserId' => v::notEmpty()->setName('创建人'),
            'currentUserIp' => v::stringVal()->setName('用户IP'),
            'sort'          => v::intVal()->setName('排序'),
        ]);
        $recommendTags = $fields['recommendTags'] ?? [];
        $fields = ArrayToolkit::parts($fields, ['name', 'code', 'parentId', 'status', 'remark', 'icon', 'cover', 'sort']);
        empty($fields['code']) && $fields['code'] = StringToolkit::chinese_to_hex($fields['name']);
        try {
            $this->beginTransaction();
            $catalog = $this->getProductCatalogDao()->create($fields);
            $this->setCatalogPath($fields['parentId'] ?? 0, $catalog['id']);
            $this->makeRecommendCatalogTags($catalog, $recommendTags);
            $this->getLogService()->info(LogEnum::MODULE_PRODUCT_CATALOG, 'add', '添加作品分类成功', $catalog, [
                'userId'    => $fields['currentUserId'] ?? null,
                'currentIp' => $fields['currentUserIp'] ?? '',
            ]);
            $this->commit();

            return true;
        } catch (\Exception $e) {
            $this->rollback();
            $this->getLogService()->error(LogEnum::MODULE_PRODUCT_CATALOG, LogEnum::ACTION_ADD_CATALOG, '添加作品分类失败，' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param int $id
     * @param array $fields
     * @return bool|array|null
     */
    public function updateProductCatalog(int $id, array $fields)
    {
        $oldCatalog = $this->getProductCatalogDao()->get($id);
        if (empty($oldCatalog)) {
            throw ProductException::CATALOG_NOTFOUND_ERROR();
        }
        !isset($fields['sort']) && $fields['sort'] = $oldCatalog['sort'];
        $nameExistRule = v::callback(function ($value) use ($oldCatalog) {
            if ($oldCatalog['name'] === $value) {
                return true;
            }
            return empty($this->getProductCatalogByName($value)) ? true : false;
        })->setTemplate('分类已存在');
        $codeExistRule = v::callback(function ($value) use ($oldCatalog) {
            if (empty($value) || $oldCatalog['code'] === $value) {
                return true;
            }

            return empty($this->getProductCatalogByCode($value)) ? true : false;
        })->setTemplate('分类编码已存在');
        $rules = [
            'name' => v::notEmpty()->addRule($nameExistRule)->setName('分类名称'),
        ];

        if (isset($fields['code'])) {
            $rules['code'] = $codeExistRule;
        }

        if (isset($fields['sort'])) {
            $rules['sort'] = v::intVal()->setName('排序');
        }

        if (isset($fields['parentId'])) {
            $rules['parentId'] = v::callback(function ($value) {
                return is_numeric($value);
            })->setTemplate('父级id必须是数字');
        }

        if (isset($fields['status'])) {
            $rules['status'] = v::intVal()->setName('状态');
        }

        if (isset($fields['remark'])) {
            $rules['remark'] = v::stringVal()->setName('备注');
        }

        if (isset($fields['icon'])) {
            $rules['icon'] = v::stringVal()->setName('图标');
        }

        if (isset($fields['cover'])) {
            $rules['cover'] = v::stringVal()->setName('封面图');
        }

        if (isset($fields['recommendTags'])) {
            $rules['recommendTags'] = v::arrayVal()->setName('推荐标签');
        }

        $fields = v::input($fields, $rules);
        $recommendTags = $fields['recommendTags'] ?? [];
        $fields = ArrayToolkit::parts($fields, ['name', 'code', 'parentId', 'status', 'remark', 'icon', 'cover', 'sort']);
        empty($fields['code']) && $fields['code'] = StringToolkit::chinese_to_hex($fields['name']);
        $this->beginTransaction();
        try {
            if ($fields['parentId'] ?? 0 != $oldCatalog['parentId']) {
                $fields['path'] = $this->getCatalogPathByParentIdAndId($fields['parentId'], $id);
            }
            $catalog = $this->getProductCatalogDao()->update($id, $fields);
            $this->makeRecommendCatalogTags($catalog, $recommendTags);
            $this->getLogService()->info(LogEnum::MODULE_PRODUCT_CATALOG, 'update', '更新作品分类成功', [
                'old' => $oldCatalog,
                'new' => $catalog,
            ]);
            $this->commit();

            return true;
        } catch (\Exception $e) {
            $this->rollback();
            $this->getLogService()->error(LogEnum::MODULE_PRODUCT_CATALOG, LogEnum::ACTION_UPDATE_CATALOG, '更新作品分类失败，' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 修改排序
     * @param int $id
     * @param int $sort
     * @return int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function updateCatalogSort(int $id, int $sort)
    {
        $oldCatalog = $this->getProductCatalogDao()->get($id);
        if (empty($oldCatalog)) {
            throw ProductException::CATALOG_NOTFOUND_ERROR();
        }

        return $this->getProductCatalogDao()->update($id, ['sort' => $sort]);
    }

    public function updateCatalogStatus(int $id, int $status)
    {
        $oldCatalog = $this->getProductCatalogDao()->get($id);
        if (empty($oldCatalog)) {
            throw ProductException::CATALOG_NOTFOUND_ERROR();
        }

        return $this->getProductCatalogDao()->update($id, ['status' => $status]);
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function getProductCatalogById(int $id) : ?array
    {
        $catalog = $this->getProductCatalogDao()->get($id);
        if (empty($catalog)) {
            throw ProductException::CATALOG_NOTFOUND_ERROR();
        }

        $catalog['recommendTags'] = ArrayToolkit::column($this->getProductCatalogTagDao()->getAll(['catalogId' => $id], null, ['tagId']), 'tagId');

        return $catalog;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function deleteProductCatalogById(int $id) : bool
    {
        if ($this->getProductCatalogDao()->count(['parentId' => $id])) {
            throw ProductException::CATALOG_HAS_CHILD();
        }

        return $this->getProductCatalogDao()->delete($id) && $this->getProductCatalogTagDao()->batchDelete(['catalogId' => $id]);
    }

    /**
     * 获取分类通过分类名称
     *
     * @param string $name
     * @return array|null
     */
    public function getProductCatalogByName(string $name) : ?array
    {
        return $this->getProductCatalogDao()->getByName($name);
    }

    /**
     * 获取分类通过分类编码
     *
     * @param string $code
     * @return array|null
     */
    public function getProductCatalogByCode(string $code) : ?array
    {
        return $this->getProductCatalogDao()->getByCode($code);
    }

    protected function getCatalogPathByParentIdAndId($parentId, $id)
    {
        if (!empty($parentId)) {
            $parentCatalog = $this->getProductCatalogDao()->get($parentId);
            if (!empty($parentCatalog)) {
                $path = $parentCatalog['path'] . $id . '.';
            } else {
                $path = $id . '.';
            }
        } else {
            $path = $id . '.';
        }

        return $path;
    }

    protected function setCatalogPath($parentId, $id)
    {
        return $this->getProductCatalogDao()->update($id, ['path' => $this->getCatalogPathByParentIdAndId($parentId, $id)]);
    }

    /**
     * 生成推荐关联tag
     *
     * @param $catalog
     * @param $recommendTags
     * @return bool
     */
    protected function makeRecommendCatalogTags($catalog, $recommendTags)
    {
        if (empty($catalog)) {
            return false;
        }

        //        $tags = $this->getProductTagDao()->getAllByIds($recommendTags);
        $dbCatalogTags = ArrayToolkit::column($this->getProductCatalogTagDao()->getAllByCatalogId($catalog['id']), 'tagId');
        $addTags = array_diff($recommendTags, $dbCatalogTags);
        $removeTags = array_diff($dbCatalogTags, $recommendTags);
        // 如果用户取消了所有标签的选择，应该将数据库中的所有标签移除
        if (empty($recommendTags)) {
            $removeTags = $dbCatalogTags;
        }

        if (!empty($addTags)) {
            $items = [];
            foreach ($addTags as $tag) {
                $items[] = [
                    'catalogId' => $catalog['id'],
                    'tagId'     => $tag,
                ];
            }

            $this->getProductCatalogTagDao()->batchCreate($items);
        }

        if (!empty($removeTags)) {
            $this->getProductCatalogTagDao()->batchDelete([
                'catalogId' => $catalog['id'],
                'tagIds'    => $removeTags,
            ]);
        }
    }


    /**
     * @return TagDao
     */
    protected function getProductTagDao()
    {
        return $this->createDao('Product:ProductTagDao');
    }

    /**
     * @return ProductCatalogDao
     */
    protected function getProductCatalogDao()
    {
        return $this->createDao('Product:ProductCatalogDao');
    }

    /**
     * @return ProductCatalogTagDao
     */
    protected function getProductCatalogTagDao()
    {
        return $this->createDao('Product:ProductCatalogTagDao');
    }
}