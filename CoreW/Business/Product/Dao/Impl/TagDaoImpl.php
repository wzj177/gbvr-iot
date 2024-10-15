<?php


namespace CoreW\Business\Product\Dao\Impl;


use CoreW\Business\Product\Dao\TagDao;
use CoreW\Dao\AdvancedDaoImpl;

class TagDaoImpl extends AdvancedDaoImpl implements TagDao
{
    protected $table = 'vr_tag';

    public function getAllByIds(array $ids): array
    {
        return $this->findInField('id', $ids);
    }

    public function getAllByTypeAndUserIdAndNames($type, $userId, $names): array
    {
        $marks = str_repeat('?,', count($names) - 1).'?';
        $sql = "SELECT `id`, `type`, `name`, `userId` FROM {$this->table()} WHERE `type` = 
 ? AND `userId` = ? AND `name` IN ($marks);";
        return $this->db()->fetchAllAssociative($sql, array_merge([$type], [$userId], $names));
    }

    public function getAllByNames(array $names): array
    {
        return $this->findInField('name', $names);
    }

    public function declares():array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'userId = :userId',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'name = :name',
                'type = :type',
                'name LIKE :keyword',
                'name in (:names)',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}