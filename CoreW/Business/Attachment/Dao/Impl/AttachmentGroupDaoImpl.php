<?php


namespace CoreW\Business\Attachment\Dao\Impl;


use CoreW\Business\Attachment\Dao\AttachmentGroupDao;
use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Dao\DynamicQueryBuilder;

class AttachmentGroupDaoImpl extends AdvancedDaoImpl implements AttachmentGroupDao
{

    protected $table = 'gv_attachment_group';

    /**
     * 获取指定编码集合的分组
     *
     * @param array $codes
     * @return array
     */
    public function getAllByCodes(array $codes)
    {
        if (empty($codes)) {
            return [];
        }

        return $this->findInField('code', $codes);
    }

    public function getByCode($code)
    {
        return $this->getByFields(['code' => $code]);
    }

    public function getByTitle($title)
    {
        return $this->getByFields(['title' => $title]);
    }

    public function declares():array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'sort',
                'parentId',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN ( :ids)',
                'id NOT IN ( :noIds)',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
                'code = :code',
                'title LIKE :keyword',
                'parentId = :parentId',
                'level = :level',
                'isDefault = :isDefault',
                'level <= :level_le'
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}