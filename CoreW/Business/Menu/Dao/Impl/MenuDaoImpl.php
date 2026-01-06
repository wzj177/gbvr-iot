<?php

namespace CoreW\Business\Menu\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Menu\Dao\MenuDao;

class MenuDaoImpl extends AdvancedDaoImpl implements MenuDao
{
    protected $table = 'gv_menu';

    public function getByMenuId(string $menuId)
    {
        return $this->getByFields(['menuId' => $menuId]);
    }

    public function findByMenuIds(array $menuIds)
    {
        if (empty($menuIds)) {
            return [];
        }
        return $this->findInField('menuId', $menuIds);
    }

    public function findByParentId(int $parentId)
    {
        return $this->search(['parentId' => $parentId], ['sort' => 'ASC'], 0, PHP_INT_MAX);
    }

    public function findByType(string $type)
    {
        return $this->search(['type' => $type], ['sort' => 'ASC'], 0, PHP_INT_MAX);
    }

    public function declares(): array
    {
        return [
            'serializes' => [],
            'orderbys' => [
                'id',
                'sort',
                'createdTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'menuId = :menuId',
                'menuId IN (:menuIds)',
                'menuId LIKE :menuIdLike',
                'name = :name',
                'name LIKE :nameLike',
                'type = :type',
                'type IN (:types)',
                'parentId = :parentId',
                'parentMenuId = :parentMenuId',
                'status = :status',
                'routeName = :routeName',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
