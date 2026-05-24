<?php

namespace CoreW\Business\SipGateway\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\SipGateway\Service\SipGatewayService;
use CoreW\Business\SipGateway\Dao\SipGatewayDao;
use CoreW\Business\SipGateway\Exception\SipGatewayException;
use CoreW\Dao\DaoProxy;
use CoreW\Dao\DaoInterface;
use support\Log;
use support\utils\ArrayToolkit;

class SipGatewayServiceImpl extends BaseService implements SipGatewayService
{
    public function createGateway(array $data) : array
    {
        if (!ArrayToolkit::requireds($data, ['gateway_id', 'gateway_name', 'server_id', 'server_domain'])) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        // Check gateway_id uniqueness
        $existing = $this->getSipGatewayDao()->getByGatewayId($data['gateway_id']);
        if (!empty($existing)) {
            throw SipGatewayException::DUPLICATE_GATEWAY_ID();
        }

        // Check host:port uniqueness
        $sipHost = $data['sip_host'] ?? '0.0.0.0';
        $sipPort = $data['sip_port'] ?? 5060;
        $existing = $this->getSipGatewayDao()->findByHostPort($sipHost, $sipPort);
        if (!empty($existing)) {
            throw SipGatewayException::DUPLICATE_HOST_PORT();
        }

        $now = date('Y-m-d H:i:s');
        $fields = ArrayToolkit::parts($data, [
            'gateway_id', 'gateway_name', 'server_id', 'server_domain',
            'sip_host', 'sip_port', 'transport', 'public_ip',
            'device_password', 'authentication', 'sip_username',
            'register_expires', 'keepalive_interval', 'heartbeat_timeout',
            'keepalive_lost_number', 'catalog_auto_query', 'encoding_type',
            'task_worker_num', 'timer_interval', 'max_devices',
            'broadcast_push_after_ack', 'mq_type', 'mq_config',
            'redis_config', 'api_config', 'log_level', 'debug',
        ]);

        $fields['sip_host'] = $sipHost;
        $fields['sip_port'] = $sipPort;
        $fields['status'] = $data['status'] ?? 'active';
        $fields['device_count'] = 0;
        $fields['created_at'] = $now;
        $fields['updated_at'] = $now;

        return $this->getSipGatewayDao()->create($fields);
    }

    public function updateGateway(int $id, array $data) : array
    {
        $gateway = $this->getSipGatewayDao()->get($id);
        if (empty($gateway)) {
            throw SipGatewayException::GATEWAY_NOT_FOUND();
        }

        // If changing gateway_id, check uniqueness
        if (isset($data['gateway_id']) && $data['gateway_id'] !== $gateway['gateway_id']) {
            $existing = $this->getSipGatewayDao()->getByGatewayId($data['gateway_id']);
            if (!empty($existing)) {
                throw SipGatewayException::DUPLICATE_GATEWAY_ID();
            }
        }

        // If changing host/port, check uniqueness
        $sipHost = $data['sip_host'] ?? $gateway['sip_host'];
        $sipPort = $data['sip_port'] ?? $gateway['sip_port'];
        if ($sipHost !== $gateway['sip_host'] || $sipPort !== $gateway['sip_port']) {
            $existing = $this->getSipGatewayDao()->findByHostPort($sipHost, $sipPort);
            if (!empty($existing) && $existing['id'] !== $id) {
                throw SipGatewayException::DUPLICATE_HOST_PORT();
            }
        }

        $fields = ArrayToolkit::parts($data, [
            'gateway_id', 'gateway_name', 'server_id', 'server_domain',
            'sip_host', 'sip_port', 'transport', 'public_ip',
            'device_password', 'authentication', 'sip_username',
            'register_expires', 'keepalive_interval', 'heartbeat_timeout',
            'keepalive_lost_number', 'catalog_auto_query', 'encoding_type',
            'task_worker_num', 'timer_interval', 'max_devices',
            'broadcast_push_after_ack', 'mq_type', 'mq_config',
            'redis_config', 'api_config', 'log_level', 'debug', 'status',
        ]);

        $fields['updated_at'] = date('Y-m-d H:i:s');

        $this->getSipGatewayDao()->update($id, $fields);

        return $this->getSipGatewayDao()->get($id);
    }

    public function deleteGateway(int $id) : bool
    {
        $gateway = $this->getSipGatewayDao()->get($id);
        if (empty($gateway)) {
            throw SipGatewayException::GATEWAY_NOT_FOUND();
        }

        // Check if there are devices bound to this gateway
        $deviceCount = $this->getDeviceDao()->count([
            'gateway_id' => $gateway['gateway_id'],
        ]);

        if ($deviceCount > 0) {
            throw SipGatewayException::GATEWAY_HAS_DEVICES();
        }

        return $this->getSipGatewayDao()->delete($id);
    }

    public function getGateway(int $id) : ?array
    {
        return $this->getSipGatewayDao()->get($id);
    }

    public function getGatewayByGatewayId(string $gatewayId) : ?array
    {
        return $this->getSipGatewayDao()->getByGatewayId($gatewayId);
    }

    public function searchGateways(array $conditions, array $orderBys, int $start, int $limit) : array
    {
        return $this->getSipGatewayDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countGateways(array $conditions) : int
    {
        return $this->getSipGatewayDao()->count($conditions);
    }

    public function toggleGateway(int $id) : array
    {
        $gateway = $this->getSipGatewayDao()->get($id);
        if (empty($gateway)) {
            throw SipGatewayException::GATEWAY_NOT_FOUND();
        }

        $newStatus = $gateway['status'] === 'active' ? 'disabled' : 'active';

        $this->getSipGatewayDao()->update($id, [
            'status'     => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->getSipGatewayDao()->get($id);
    }

    public function getGatewayFullConfig(string $gatewayId) : ?array
    {
        $gateway = $this->getSipGatewayDao()->getByGatewayId($gatewayId);
        if (empty($gateway)) {
            return null;
        }

        if ($gateway['status'] === 'disabled') {
            throw SipGatewayException::GATEWAY_DISABLED();
        }

        // Build redis_config with gateway-specific queue_name
        $redisConfig = $gateway['redis_config'] ?? [];
        if (empty($redisConfig)) {
            $redisConfig = [
                'host'     => '127.0.0.1',
                'password' => null,
                'port'     => 6379,
                'database' => 11,
                'prefix'   => 'gbvr_iot_gb_gateway_',
                'queue_name' => 'gb28181:commands',
            ];
        }
        // 确保 queue_name 存在
        if (!isset($redisConfig['queue_name'])) {
            $redisConfig['queue_name'] = 'gb28181:commands';
        }

        // Build api_config with defaults
        $apiConfig = $gateway['api_config'] ?? [];
        if (empty($apiConfig)) {
            $apiConfig = [
                'hock_url' => '',
                'pull_url' => '',
                'token'    => '',
            ];
        }

        return [
            'gateway_id'               => $gateway['gateway_id'],
            'server_id'                => $gateway['server_id'],
            'server_domain'            => $gateway['server_domain'],
            'sip_host'                 => $gateway['sip_host'],
            'sip_port'                 => $gateway['sip_port'],
            'transport'                => $gateway['transport'],
            'public_ip'                => $gateway['public_ip'] ?? '',
            'device_password'          => $gateway['device_password'] ?? '',
            'authentication'           => (bool)($gateway['authentication'] ?? true),
            'sip_username'             => $gateway['sip_username'] ?? '',
            'register_expires'         => (int)($gateway['register_expires'] ?? 3600),
            'keepalive_lost_number'    => (int)($gateway['keepalive_lost_number'] ?? 3),
            'catalog_auto_query'       => (bool)($gateway['catalog_auto_query'] ?? true),
            'encoding_type'            => $gateway['encoding_type'] ?? 'GB2312',
            'task_worker_num'          => (int)($gateway['task_worker_num'] ?? 4),
            'timer_interval'           => (int)($gateway['timer_interval'] ?? 60),
            'max_devices'              => (int)($gateway['max_devices'] ?? 10000),
            'broadcast_push_after_ack' => (bool)($gateway['broadcast_push_after_ack'] ?? true),
            'debug'                    => (bool)($gateway['debug'] ?? false),
            'log_level'                => $gateway['log_level'] ?? 'INFO',
            'mq_type'                  => $gateway['mq_type'] ?? 'redis',
            'mq_config'                => $gateway['mq_config'] ?? [],
            'redis_config'             => $redisConfig,
            'api_config'               => $apiConfig,
            'heartbeat_timeout'        => (int)($gateway['heartbeat_timeout'] ?? 180),
            'keepalive_interval'       => (int)($gateway['keepalive_interval'] ?? 60),
            'check_interval'           => (int)($gateway['timer_interval'] ?? 60),
        ];
    }

    public function updateHeartbeat(string $gatewayId, array $info) : bool
    {
        $gateway = $this->getSipGatewayDao()->getByGatewayId($gatewayId);
        if (empty($gateway)) {
            Log::channel('default')->warning("SipGateway heartbeat: gateway not found", ['gateway_id' => $gatewayId]);
            return false;
        }

        $fields = [
            'last_seen_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'status'       => 'active',
        ];

        if (isset($info['pid'])) {
            $fields['pid'] = (int)$info['pid'];
        }
        if (isset($info['ip'])) {
            $fields['ip'] = $info['ip'];
        }
        if (isset($info['device_count'])) {
            $fields['device_count'] = (int)$info['device_count'];
        }

        $this->getSipGatewayDao()->update($gateway['id'], $fields);

        return true;
    }

    public function checkOfflineGateways() : array
    {
        $offlineGateways = [];
        $gateways = $this->getSipGatewayDao()->search(['status' => 'active'], ['id' => 'ASC'], 0, 1000);

        $threshold = time() - 90; // 90 seconds
        foreach ($gateways as $gateway) {
            if (empty($gateway['last_seen_at'])) {
                continue;
            }

            $lastSeen = strtotime($gateway['last_seen_at']);
            if ($lastSeen < $threshold) {
                $this->getSipGatewayDao()->update($gateway['id'], [
                    'status'     => 'inactive',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $offlineGateways[] = $gateway['gateway_id'];
            }
        }

        return $offlineGateways;
    }

    public function bindDeviceToGateway(string $deviceId, string $gatewayId) : bool
    {
        $gateway = $this->getSipGatewayDao()->getByGatewayId($gatewayId);
        if (empty($gateway)) {
            Log::channel('default')->warning("bindDeviceToGateway: gateway not found", ['gateway_id' => $gatewayId]);
            return false;
        }

        $device = $this->getDeviceDao()->getByDeviceId($deviceId);
        if (empty($device)) {
            Log::channel('default')->warning("bindDeviceToGateway: device not found", ['device_id' => $deviceId]);
            return false;
        }

        $this->getDeviceDao()->update($device['id'], [
            'gateway_id' => $gatewayId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function bindDevicesToGateway(array $deviceIds, string $gatewayId) : array
    {
        if (empty($deviceIds)) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        $gateway = $this->getSipGatewayDao()->getByGatewayId($gatewayId);
        if (empty($gateway)) {
            throw SipGatewayException::GATEWAY_NOT_FOUND();
        }

        $success = 0;
        $failed = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($deviceIds as $deviceId) {
            $device = $this->getDeviceDao()->getByDeviceId($deviceId);
            if (empty($device)) {
                $failed++;
                continue;
            }

            $this->getDeviceDao()->update($device['id'], [
                'gateway_id' => $gatewayId,
                'updated_at' => $now,
            ]);
            $success++;
        }

        return ['success' => $success, 'failed' => $failed];
    }

    public function unbindDeviceFromGateway(string $deviceId) : bool
    {
        $device = $this->getDeviceDao()->getByDeviceId($deviceId);
        if (empty($device)) {
            Log::channel('default')->warning("unbindDeviceFromGateway: device not found", ['device_id' => $deviceId]);
            return false;
        }

        $this->getDeviceDao()->update($device['id'], [
            'gateway_id' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function registerGateway(array $data) : array
    {
        if (empty($data['gateway_id'])) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        $gatewayId = $data['gateway_id'];
        $existing = $this->getSipGatewayDao()->getByGatewayId($gatewayId);

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            // 已存在：更新所有 Gateway 上报的配置字段 + 运行时字段
            $fields = ArrayToolkit::parts($data, [
                'gateway_name', 'server_id', 'server_domain',
                'sip_host', 'sip_port', 'transport', 'public_ip',
                'device_password', 'authentication', 'sip_username',
                'register_expires', 'keepalive_interval', 'heartbeat_timeout',
                'keepalive_lost_number', 'catalog_auto_query', 'encoding_type',
                'task_worker_num', 'timer_interval', 'max_devices',
                'broadcast_push_after_ack', 'mq_type', 'mq_config',
                'redis_config', 'api_config', 'log_level', 'debug',
                'pid', 'ip',
            ]);

            $fields['status']       = 'active';
            $fields['last_seen_at'] = $now;
            $fields['updated_at']   = $now;
            $fields['debug'] = isset($fields['debug']) ? intval($fields['debug']) : 0;

            if (isset($fields['sip_port'])) {
                $fields['sip_port'] = (int)$fields['sip_port'];
            }
            if (isset($fields['authentication'])) {
                $fields['authentication'] = (int)(bool)$fields['authentication'];
            }
            if (isset($fields['pid'])) {
                $fields['pid'] = (int)$fields['pid'];
            }

            $this->getSipGatewayDao()->update($existing['id'], $fields);
            return $this->getSipGatewayDao()->get($existing['id']);
        }

        // 不存在：创建新记录（完整字段写入）
        $fields = ArrayToolkit::parts($data, [
            'gateway_id', 'gateway_name', 'server_id', 'server_domain',
            'sip_host', 'sip_port', 'transport', 'public_ip',
            'device_password', 'authentication', 'sip_username',
            'register_expires', 'keepalive_interval', 'heartbeat_timeout',
            'keepalive_lost_number', 'catalog_auto_query', 'encoding_type',
            'task_worker_num', 'timer_interval', 'max_devices',
            'broadcast_push_after_ack', 'mq_type', 'mq_config',
            'redis_config', 'api_config', 'log_level', 'debug',
            'pid', 'ip',
        ]);

        // 必填/默认值兜底
        $fields['gateway_id']    = $gatewayId;
        $fields['gateway_name']  = $data['gateway_name'] ?? ('Gateway-' . $gatewayId);
        $fields['server_id']     = $data['server_id'] ?? '';
        $fields['server_domain'] = $data['server_domain'] ?? '';
        $fields['sip_host']      = $data['sip_host'] ?? '0.0.0.0';
        $fields['sip_port']      = (int)($data['sip_port'] ?? 5060);
        $fields['transport']     = $data['transport'] ?? 'UDP';
        $fields['status']        = 'active';
        $fields['device_count']  = 0;
        $fields['last_seen_at']  = $now;
        $fields['created_at']    = $now;
        $fields['updated_at']    = $now;

        // 类型修正
        if (isset($fields['authentication'])) {
            $fields['authentication'] = (int)(bool)$fields['authentication'];
        }
        if (isset($fields['pid'])) {
            $fields['pid'] = (int)$fields['pid'];
        }

        return $this->getSipGatewayDao()->create($fields);
    }

    public function unbindDevicesFromGateway(array $deviceIds) : array
    {
        if (empty($deviceIds)) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        $success = 0;
        $failed = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($deviceIds as $deviceId) {
            $device = $this->getDeviceDao()->getByDeviceId($deviceId);
            if (empty($device)) {
                $failed++;
                continue;
            }

            $this->getDeviceDao()->update($device['id'], [
                'gateway_id' => null,
                'updated_at' => $now,
            ]);
            $success++;
        }

        return ['success' => $success, 'failed' => $failed];
    }

    protected function getSipGatewayDao() : SipGatewayDao|DaoInterface|DaoProxy
    {
        return $this->createDao('SipGateway:SipGatewayDao');
    }

    protected function getDeviceDao() : \CoreW\Business\Devices\Dao\DeviceDao|DaoInterface|DaoProxy
    {
        return $this->createDao('Devices:DeviceDao');
    }
}
