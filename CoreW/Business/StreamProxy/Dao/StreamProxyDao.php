<?php

namespace CoreW\Business\StreamProxy\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface StreamProxyDao extends AdvancedDaoInterface
{
    public function getByProxyId(string $proxyId);

    public function findByIds(array $ids);

    public function findByProxyIds(array $proxyIds);

    public function findByMediaServerId(string $mediaServerId);

    public function findByStatus(string $status);

    public function findByType(string $type);

    public function findByRecordPlanId(int $recordPlanId);
}
