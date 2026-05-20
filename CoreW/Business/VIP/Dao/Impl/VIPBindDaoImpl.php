<?php

namespace CoreW\Business\VIP\Dao\Impl;

use CoreW\Business\VIP\Dao\VIPBindDao;
use CoreW\Dao\GeneralDaoImpl;

class VIPBindDaoImpl extends GeneralDaoImpl implements VIPBindDao
{
    protected $table = 'gv_vip_bind';

    public function getByFromId($fromId)
    {
        return $this->getByFields(['fromId' => $fromId]);
    }

    public function getByTypeAndFromId($type, $fromId)
    {
        return $this->getByFields(['fromId' => $fromId, 'type' => $type]);
    }

    public function getByToIdAndType($type, $toId)
    {
        return $this->getByFields(['toId' => $toId, 'type' => $type]);
    }

    public function getByToken($token)
    {
        return $this->getByFields(['token' => $token]);
    }

    public function findByToId($toId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE toId = ? ORDER BY createdTime DESC";

        return $this->db()->fetchAll($sql, [$toId]);
    }

    public function findByTypeAndFromIds($type, $fromIds)
    {
        if (empty($fromIds)) {
            return [];
        }
        $marks = str_repeat('?,', count($fromIds) - 1) . '?';
        $sql = "SELECT * FROM {$this->table} WHERE type = ? AND fromId IN ({$marks})";

        return $this->db()->fetchAll($sql, array_merge([$type], $fromIds)) ? : [];
    }

    public function findByTypeAndToIds($type, $toIds)
    {
        if (empty($toIds)) {
            return [];
        }
        $marks = str_repeat('?,', count($toIds) - 1) . '?';
        $sql = "SELECT * FROM {$this->table} WHERE type = ? AND toId IN ({$marks})";

        return $this->db()->fetchAll($sql, array_merge([$type], $toIds)) ? : [];
    }

    public function findByToIdAndType($type, $toId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE toId = ? AND type = ? ORDER BY createdTime DESC";

        return $this->db()->fetchAll($sql, [$toId, $type]);
    }

    public function deleteByTypeAndToId($type, $toId)
    {
        return $this->db()->delete($this->table, ['type' => $type, 'toId' => $toId]);
    }

    public function deleteByToId($toId)
    {
        return $this->db()->delete($this->table, ['toId' => $toId]);
    }

    public function declares() : array
    {
        return [
            'conditions' => [
                'fromId = :fromId',
                'toId = :toId',
                'type = :type',
            ],
        ];
    }
}