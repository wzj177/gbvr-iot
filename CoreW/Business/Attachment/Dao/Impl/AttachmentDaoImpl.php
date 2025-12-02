<?php

namespace CoreW\Business\Attachment\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Attachment\Dao\AttachmentDao;

class AttachmentDaoImpl extends AdvancedDaoImpl implements AttachmentDao 
{

    protected $table = 'gv_attachment';

    public function getByHashId(string $hashId)
    {
        return $this->getByFields(['hashId' => $hashId]);
    }

    public function getAllByIds($ids)
    {
        return $this->findInField('id', $ids);
    }

    public function batchChangeGroupCode($ids, $groupCode)
    {
        return $this->update(['ids' => $ids], ['groupCode' => $groupCode]);
    }

    public function getOneByStorageAndPath(string $storage, string $path)
    {
        return $this->getByFields([
            'storage' => $storage,
            'filepath' => $path
        ]);
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
                'hashId = :hashId',
                'id > :id_GT',
                'id IN ( :ids)',
                'id NOT IN ( :noIds)',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
                'groupCode = :group',
                'type = :type',
                'status = :status',
                'createClient = :createClient',
                'storage = :storage',
                '(filename LIKE :keyword OR UPPER(ext) LIKE :keyword)',
            ],
            'timestamps' => [ 
                'createdTime',
                'updatedTime',
           ], 
        ];
    } 
}
