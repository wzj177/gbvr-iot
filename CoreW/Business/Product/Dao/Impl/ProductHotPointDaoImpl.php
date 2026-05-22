<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Business\Product\Dao\ProductHotPointDao;
use CoreW\Dao\AdvancedDaoImpl;

class ProductHotPointDaoImpl extends AdvancedDaoImpl implements ProductHotPointDao
{
    protected $table = "vr_product_hotpot";

    public function getByUUID(string $uuid)
    {
        return $this->getByFields(['uuid' => $uuid]);
    }

    public function deleteBySceneId(int $sceneId)
    {
        return $this->db()->delete($this->table(), ['sceneId' => $sceneId]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
                'imgUrls'               => 'delimiter',
                'iconMarkerParams'      => 'json',
                'iconTitleMarkerParams' => 'json',
                //                'content' => 'json'
            ],
            'orderbys'   => [
                'id',
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
                'productId = :productId',
                'sceneId = :sceneId',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}