<?php

namespace CoreW\Business\StreamProxy\Service;

interface StreamProxyService
{
    // CRUD operations
    public function createProxy(array $fields): array;

    public function updateProxy(int $id, array $fields): bool;

    public function deleteProxy(int $id): bool;

    public function getProxy(int $id): ?array;

    public function getProxyByProxyId(string $proxyId): ?array;

    public function searchProxies(array $conditions, array $orderBys, int $start, int $limit): array;

    public function countProxies(array $conditions): int;

    // Stream control operations
    public function startProxy(int $id): array;

    public function stopProxy(int $id): bool;

    public function restartProxy(int $id): array;

    // Play URLs
    public function getPlayUrls(int $id): array;

    public function getPushUrl(int $id): array;

    // Status management
    public function updateStatus(int $id, string $status, ?string $errorMessage = null): bool;

    public function healthCheck(int $id): bool;

    public function batchHealthCheck(): array;

    public function autoReconnect(): array;

    // Recording plan
    public function bindRecordPlan(int $id, int $planId): bool;

    public function unbindRecordPlan(int $id): bool;

    // Statistics
    public function updateViewerCount(int $id, int $count): bool;

    public function incrementRetryCount(int $id): bool;

    public function resetRetryCount(int $id): bool;

    // Logging
    public function addLog(string $proxyId, string $eventType, string $message, ?array $details = null, ?int $userId = null, ?string $ipAddress = null, string $level = 'info'): array;

    public function getProxyLogs(int $id, int $start = 0, int $limit = 100): array;

    public function searchLogs(array $conditions, array $orderBys, int $start, int $limit): array;

    public function countLogs(array $conditions): int;

    public function cleanupOldLogs(int $daysToKeep = 30): int;
}
