<?php

namespace CoreW\Business\MediaServer\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface MediaServerDao extends AdvancedDaoInterface
{
    public function getByServerId(string $serverId);

    public function findByIds(array $ids);

    public function findByServerIds(array $serverIds);
}
