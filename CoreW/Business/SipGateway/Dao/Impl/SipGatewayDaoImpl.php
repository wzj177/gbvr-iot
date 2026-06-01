<?php

namespace CoreW\Business\SipGateway\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\SipGateway\Dao\SipGatewayDao;

class SipGatewayDaoImpl extends AdvancedDaoImpl implements SipGatewayDao
{
    protected $table = 'gv_sip_gateways';

    public function getByGatewayId(string $gatewayId)
    {
        return $this->getByFields(['gateway_id' => $gatewayId]);
    }

    public function findByStatus(string $status)
    {
        return $this->findByFields(['status' => $status]);
    }

    public function findByHostPort(string $sipHost, int $sipPort)
    {
        return $this->getByFields([
            'sip_host' => $sipHost,
            'sip_port' => $sipPort,
        ]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
                'mq_config'    => 'json',
                'redis_config' => 'json',
                'api_config'   => 'json',
            ],
            'orderbys'   => [
                'id',
                'created_at',
                'last_seen_at',
            ],
            'datetime' => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'gateway_id = :gateway_id',
                'gateway_id IN (:gateway_ids)',
                'status = :status',
                'status IN (:statuses)',
                'sip_port = :sip_port',
                'mq_type = :mq_type',
                'server_id = :server_id',
                'gateway_name LIKE :gateway_name_like',
            ],
        ];
    }
}
