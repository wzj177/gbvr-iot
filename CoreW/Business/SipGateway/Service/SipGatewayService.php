<?php

namespace CoreW\Business\SipGateway\Service;

interface SipGatewayService
{
    public function createGateway(array $data) : array;

    public function updateGateway(int $id, array $data) : array;

    public function deleteGateway(int $id) : bool;

    public function getGateway(int $id) : ?array;

    public function getGatewayByGatewayId(string $gatewayId) : ?array;

    public function searchGateways(array $conditions, array $orderBys, int $start, int $limit) : array;

    public function countGateways(array $conditions) : int;

    public function toggleGateway(int $id) : array;

    public function getGatewayFullConfig(string $gatewayId) : ?array;

    public function updateHeartbeat(string $gatewayId, array $info) : bool;

    public function checkOfflineGateways() : array;

    public function bindDeviceToGateway(string $deviceId, string $gatewayId) : bool;

    /**
     * 批量绑定设备到网关
     * @param string[] $deviceIds 设备ID列表
     * @param string $gatewayId 网关ID
     * @return array ['success' => int, 'failed' => int]
     */
    public function bindDevicesToGateway(array $deviceIds, string $gatewayId) : array;

    /**
     * 单个设备解绑网关
     */
    public function unbindDeviceFromGateway(string $deviceId) : bool;

    /**
     * 批量设备解绑网关
     * @param string[] $deviceIds 设备ID列表
     * @return array ['success' => int, 'failed' => int]
     */
    public function unbindDevicesFromGateway(array $deviceIds) : array;

    /**
     * 网关自动注册（Gateway启动时调用）
     * 如果 gateway_id 已存在则更新部分字段，不存在则创建
     * @param array $data 网关信息
     * @return array 网关记录
     */
    public function registerGateway(array $data) : array;
}
