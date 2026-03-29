<?php

namespace CoreW\Business\SipGateway\Service;

interface SipGatewayService
{
    public function createGateway(array $data): array;

    public function updateGateway(int $id, array $data): array;

    public function deleteGateway(int $id): bool;

    public function getGateway(int $id): ?array;

    public function getGatewayByGatewayId(string $gatewayId): ?array;

    public function searchGateways(array $conditions, array $orderBys, int $start, int $limit): array;

    public function countGateways(array $conditions): int;

    public function toggleGateway(int $id): array;

    public function getGatewayFullConfig(string $gatewayId): ?array;

    public function updateHeartbeat(string $gatewayId, array $info): bool;

    public function checkOfflineGateways(): array;

    public function bindDeviceToGateway(string $deviceId, string $gatewayId): bool;
}
