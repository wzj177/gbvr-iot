<?php

namespace CoreW\Business\SipGateway\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface SipGatewayDao extends AdvancedDaoInterface
{
    public function getByGatewayId(string $gatewayId);

    public function findByStatus(string $status);

    public function findByHostPort(string $sipHost, int $sipPort);
}
