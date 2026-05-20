<?php

namespace CoreW\Business\Snapshot\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface SnapshotFileDao extends AdvancedDaoInterface
{
    /**
     * 根据来源查询快照
     */
    public function getBySource(string $sourceType, ?int $sourceId) : array;
}
