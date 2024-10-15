<?php

namespace CoreW\Business\VIP\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface VIPDao extends AdvancedDaoInterface
{
    public function getByNickname($nickname);

    public function getByEmail($email);

    public function getByPhone($phone);

    public function getByInviteCode($inviteCode);

    public function getByUUID($uuid);
}
