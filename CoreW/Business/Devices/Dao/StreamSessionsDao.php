<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface StreamSessionsDao extends AdvancedDaoInterface
{
    public function getByCallId(int $callId);
    public function getByStreamId(string $callId);

    public function deleteAllByExpireTime(int $expireTime): int|string;

    public function deleteByDeviceId(string $deviceId): int|string;

}
