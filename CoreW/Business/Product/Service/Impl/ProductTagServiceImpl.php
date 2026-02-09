<?php


namespace CoreW\Business\Product\Service\Impl;


use CoreW\Business\BaseService;
use CoreW\Business\Product\Dao\ProductCatalogTagDao;
use CoreW\Business\Product\Dao\TagDao;
use CoreW\Business\Product\Exception\ProductException;
use CoreW\Business\Product\Service\ProductTagService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use support\utils\ArrayToolkit;

class ProductTagServiceImpl extends BaseService implements ProductTagService
{

    /**
     * 计算总数
     *
     * @param array $conditions
     * @return int
     */
    public function countTag(array $conditions): int
    {
        return $this->getTagDao()->count($conditions);
    }

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
    public function searchTags(array $conditions, array $orderBys, int $start, int $limit, array $columns = []): array
    {
        if (!empty($conditions['catalog_id'])) {
            $catalogTags = $this->getCatalogTagDao()->getAllByCatalogId($conditions['catalog_id']);
            $tagIds = ArrayToolkit::column($catalogTags, 'tagId');
            if (empty($tagIds)) {
                return [];
            }

            $conditions['ids'] = $tagIds;
        }
        
        return $this->getTagDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    /**
     * 生成tag
     *
     * @param int $userId
     * @param string $tags
     * @param string $type
     * @return bool
     */
    public function addTags($userId, string $tags, $type = 'system'): bool
    {
        if (empty($tags)) {
            throw ProductException::TAG_ADD_PARAMETER_ERROR();
        }

        $tags = array_unique(explode("|", $tags));
        $dbTagItems = $this->getTagDao()->getAllByNames($tags);
        $oldTags = ArrayToolkit::column($dbTagItems, 'name');
        $tags = array_diff($tags, $oldTags);
        if (empty($tags)) {
            throw ProductException::TAG_ADD_NEED_NULL_ERROR();
        }

        $items = [];
        foreach ($tags as $tag) {
            $items[] = [
                'type' => $type,
                'userId' => $userId,
                'name' => $tag,
                'createdTime' => time(),
                'updatedTime' => time(),
            ];
        }

        return $this->getTagDao()->batchCreate($items);
    }


    /**
     * @param array $tags
     * @return mixed
     */
    public function batchCreateTags(array $tags)
    {
        return $this->getTagDao()->batchCreate($tags);
    }

    /**
     * 删除tag
     *
     * @param int $id
     * @return bool
     */
    public function deleteTagById(int $id): bool
    {
        // TODO: 校验是否与作品绑定（与product_tag_item判断）
        return $this->getTagDao()->delete($id);
    }

    public function deleteCustomTagsByUserId($userId)
    {
        return $this->getTagDao()->batchDelete(['userId' => $userId, 'type' => 'custom']);
    }

    /**
     * 批量删除
     *
     * @param array $ids
     * @param int|null $userId
     * @param string|null $userIp
     * @return bool
     */
    public function batchDeleteByIds(array $ids, ?int $userId, ?string $userIp): bool
    {
        if (empty($ids)) {
            return false;
        }

        // TODO: 校验是否与作品绑定（与product_tag_item取交集）
        $this->getTagDao()->batchDelete(['ids' => $ids]);
        $total = count($ids);
        $this->getLogService()->info(LogEnum::MODULE_PRODUCT_TAG, LogEnum::ACTION_DELETE_TAGS, "批量删除{$total}个标签数据", $ids, ['userId' => $userId, 'currentIp' => $userIp]);

        return true;
    }

    /**
     * @return TagDao
     */
    protected function getTagDao()
    {
        return $this->createDao("Product:TagDao");
    }

    /**
     * @return ProductCatalogTagDao
     */
    protected function getCatalogTagDao()
    {
        return $this->createDao('Product:ProductCatalogTagDao');
    }
}