<?php

namespace CoreW\Business\StreamProxy\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\StreamProxy\Service\StreamProxyService;
use CoreW\Business\StreamProxy\Dao\StreamProxyDao;
use CoreW\Business\StreamProxy\Exception\StreamProxyException;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\MediaServer\Strategy\MediaServerStrategyFactory;
use CoreW\Business\Record\Service\RecordPlanService;
use support\utils\ArrayToolkit;
use support\Log;

class StreamProxyServiceImpl extends BaseService implements StreamProxyService
{
    // ==================== CRUD Operations ====================

    public function createProxy(array $fields) : array
    {
        // Validate required fields
        if (!ArrayToolkit::requireds($fields, ['name', 'type', 'protocol', 'media_server_id'])) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        // Validate type
        if (!in_array($fields['type'], ['pull', 'push'])) {
            throw StreamProxyException::INVALID_PROXY_TYPE();
        }

        // Validate protocol
        if (!in_array($fields['protocol'], ['rtsp', 'rtmp', 'http-flv'])) {
            throw StreamProxyException::INVALID_PROTOCOL();
        }

        // For pull type, source_url is required
        if ($fields['type'] === 'pull' && empty($fields['source_url'])) {
            throw StreamProxyException::MISSING_SOURCE_URL();
        }

        // Verify media server exists
        $mediaServer = $this->getMediaServerService()->getMediaServer($fields['media_server_id']);
        if (!$mediaServer) {
            throw StreamProxyException::MEDIA_SERVER_NOT_FOUND();
        }

        // Generate proxy_id
        $fields['proxy_id'] = $this->generateUuid();

        // Handle stream ID
        // For push type: allow custom stream ID (for OBS), or generate if not provided
        // For pull type: generate UUID if not provided
        if (empty($fields['stream'])) {
            $fields['stream'] = $this->generateUuid();
        } else {
            // Validate custom stream ID (alphanumeric, dash, underscore only)
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $fields['stream'])) {
                throw new StreamProxyException(
                    StreamProxyException::ERROR_PARAMETER,
                    'Stream ID must contain only alphanumeric characters, dash and underscore'
                );
            }
        }

        $fields['app'] = $fields['app'] ?? ($fields['type'] === 'pull' ? 'proxy' : 'push');
        $fields['vhost'] = $fields['vhost'] ?? '__defaultVhost__';

        // Check for duplicate app/stream combination
        $existing = $this->getStreamProxyDao()->search([
            'app'           => $fields['app'],
            'stream'        => $fields['stream'],
            'mediaServerId' => $fields['media_server_id'],
        ], [], 0, 1);

        if (!empty($existing)) {
            throw StreamProxyException::DUPLICATE_APP_STREAM();
        }

        // Set default values
        $fields['status'] = 'stopped';
        $fields['record_plan_id'] = $fields['record_plan_id'] ?? 0;
        $fields['record_status'] = 0;
        $fields['enable_auto_reconnect'] = $fields['enable_auto_reconnect'] ?? 1;
        $fields['max_retry_count'] = $fields['max_retry_count'] ?? 10;
        $fields['current_retry_count'] = 0;
        $fields['timeout_sec'] = $fields['timeout_sec'] ?? 10;
        $fields['rtp_type'] = $fields['rtp_type'] ?? 0;
        $fields['enable_hls'] = $fields['enable_hls'] ?? 1;
        $fields['enable_mp4'] = $fields['enable_mp4'] ?? 0;
        $fields['viewer_count'] = 0;
        $fields['total_start_count'] = 0;
        $fields['total_reconnect_count'] = 0;

        // Filter allowed fields
        $fields = ArrayToolkit::parts($fields, [
            'proxy_id', 'name', 'type', 'protocol', 'source_url',
            'app', 'stream', 'vhost', 'media_server_id',
            'status', 'record_plan_id', 'record_status',
            'enable_auto_reconnect', 'max_retry_count', 'current_retry_count',
            'timeout_sec', 'rtp_type', 'enable_hls', 'enable_mp4',
            'viewer_count', 'total_start_count', 'total_reconnect_count',
            'description', 'tags',
        ]);

        $proxy = $this->getStreamProxyDao()->create($fields);

        // Log creation
        $this->addLog(
            $proxy['proxy_id'],
            'created',
            "流代理 [{$proxy['name']}] 已创建",
            ['type' => $proxy['type'], 'protocol' => $proxy['protocol']],
            null,
            null,
            'info'
        );

        return $proxy;
    }

    public function updateProxy(int $id, array $fields) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Cannot update certain fields when proxy is running
        if ($proxy['status'] === 'online') {
            $forbiddenFields = ['type', 'protocol', 'source_url', 'app', 'stream', 'media_server_id'];
            foreach ($forbiddenFields as $field) {
                if (isset($fields[$field])) {
                    unset($fields[$field]);
                }
            }
        }

        // Filter allowed fields
        $fields = ArrayToolkit::parts($fields, [
            'name', 'source_url', 'enable_auto_reconnect', 'max_retry_count',
            'timeout_sec', 'rtp_type', 'enable_hls', 'enable_mp4',
            'description', 'tags',
        ]);

        if (empty($fields)) {
            return true;
        }

        $result = $this->getStreamProxyDao()->update($id, $fields);

        return !empty($result);
    }

    public function deleteProxy(int $id) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Stop proxy first if it's running
        if ($proxy['status'] === 'online') {
            $this->stopProxy($id);
        }

        // Log deletion before deleting
        $this->addLog(
            $proxy['proxy_id'],
            'deleted',
            "流代理 [{$proxy['name']}] 已删除",
            null,
            null,
            null,
            'warning'
        );

        // Delete proxy
        $this->getStreamProxyDao()->delete($id);

        // Delete all logs for this proxy
        $this->getStreamProxyLogDao()->deleteByProxyId($proxy['proxy_id']);

        return true;
    }

    public function getProxy(int $id) : ?array
    {
        return $this->getStreamProxyDao()->get($id);
    }

    public function getProxyByProxyId(string $proxyId) : ?array
    {
        return $this->getStreamProxyDao()->getByProxyId($proxyId);
    }

    public function searchProxies(array $conditions, array $orderBys, int $start, int $limit) : array
    {
        return $this->getStreamProxyDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countProxies(array $conditions) : int
    {
        return $this->getStreamProxyDao()->count($conditions);
    }

    // ==================== Stream Control Operations ====================

    public function startProxy(int $id) : array
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Check current status
        if ($proxy['status'] === 'online') {
            throw StreamProxyException::PROXY_ALREADY_STARTED();
        }

        // Only pull type can be started
        if ($proxy['type'] !== 'pull') {
            throw new StreamProxyException(
                StreamProxyException::START_FAILED,
                'Only pull type proxies can be started'
            );
        }

        // Get media server config
        $mediaServer = $this->getMediaServerService()->getMediaServer($proxy['media_server_id']);
        if (!$mediaServer) {
            throw StreamProxyException::MEDIA_SERVER_NOT_FOUND();
        }

        // Get strategy and add stream proxy
        $strategy = MediaServerStrategyFactory::create($mediaServer['type']);
        $result = $strategy->addStreamProxy($mediaServer, [
            'vhost'       => $proxy['vhost'],
            'app'         => $proxy['app'],
            'stream'      => $proxy['stream'],
            'url'         => $proxy['source_url'],
            'retry_count' => -1, // Always auto-retry at ZLM level
            'rtp_type'    => $proxy['rtp_type'],
            'timeout_sec' => $proxy['timeout_sec'],
            'enable_hls'  => (bool)$proxy['enable_hls'],
            'enable_mp4'  => (bool)$proxy['enable_mp4'],
        ]);

        if (!$result['success']) {
            throw new StreamProxyException(
                StreamProxyException::START_FAILED,
                $result['message'] ?? 'Failed to start stream proxy'
            );
        }

        // Update proxy status
        $this->getStreamProxyDao()->update($id, [
            'status'              => 'online',
            'zlm_key'             => $result['key'],
            'started_at'          => date('Y-m-d H:i:s'),
            'last_heartbeat_at'   => date('Y-m-d H:i:s'),
            'current_retry_count' => 0,
            'error_message'       => null,
            'total_start_count'   => $proxy['total_start_count'] + 1,
        ]);

        Log::channel('stream_proxy')->info("Stream proxy started", [
            'id'       => $id,
            'proxy_id' => $proxy['proxy_id'],
            'zlm_key'  => $result['key'],
        ]);

        // Log start event
        $this->addLog(
            $proxy['proxy_id'],
            'started',
            "流代理 [{$proxy['name']}] 已启动",
            ['zlm_key' => $result['key'], 'source_url' => $proxy['source_url']],
            null,
            null,
            'info'
        );

        return $this->getProxy($id);
    }

    public function stopProxy(int $id) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Check if proxy is running
        if ($proxy['status'] === 'stopped') {
            throw StreamProxyException::PROXY_ALREADY_STOPPED();
        }

        // Get media server config
        $mediaServer = $this->getMediaServerService()->getMediaServer($proxy['media_server_id']);
        if (!$mediaServer) {
            throw StreamProxyException::MEDIA_SERVER_NOT_FOUND();
        }

        // Delete stream proxy from ZLM
        if (!empty($proxy['zlm_key'])) {
            $strategy = MediaServerStrategyFactory::create($mediaServer['type']);
            $strategy->delStreamProxy($mediaServer, $proxy['zlm_key']);
        }

        // Update proxy status
        $this->getStreamProxyDao()->update($id, [
            'status'        => 'stopped',
            'stopped_at'    => date('Y-m-d H:i:s'),
            'zlm_key'       => null,
            'error_message' => null,
        ]);

        Log::channel('stream_proxy')->info("Stream proxy stopped", [
            'id'       => $id,
            'proxy_id' => $proxy['proxy_id'],
        ]);

        // Log stop event
        $this->addLog(
            $proxy['proxy_id'],
            'stopped',
            "流代理 [{$proxy['name']}] 已停止",
            null,
            null,
            null,
            'info'
        );

        return true;
    }

    public function restartProxy(int $id) : array
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Stop first if running
        if ($proxy['status'] === 'online') {
            $this->stopProxy($id);
        }

        // Start again
        return $this->startProxy($id);
    }

    // ==================== Play URLs ====================

    public function getPlayUrls(int $id) : array
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Get media server config
        $mediaServer = $this->getMediaServerService()->getMediaServer($proxy['media_server_id']);
        if (!$mediaServer) {
            throw StreamProxyException::MEDIA_SERVER_NOT_FOUND();
        }

        $host = $mediaServer['stream_ip'] ?? $mediaServer['host'];
        $httpPort = $mediaServer['port'];
        $httpsPort = $mediaServer['https_port'] ?? 4443;
        $rtspPort = $mediaServer['rtsp_port'] ?? 554;
        $rtmpPort = $mediaServer['rtmp_port'] ?? 1935;

        $app = $proxy['app'];
        $stream = $proxy['stream'];

        return [
            'rtsp'      => "rtsp://{$host}:{$rtspPort}/{$app}/{$stream}",
            'rtmp'      => "rtmp://{$host}:{$rtmpPort}/{$app}/{$stream}",
            'http_flv'  => "http://{$host}:{$httpPort}/{$app}/{$stream}.live.flv",
            'ws_flv'    => "ws://{$host}:{$httpPort}/{$app}/{$stream}.live.flv",
            'hls'       => "http://{$host}:{$httpPort}/{$app}/{$stream}/hls.m3u8",
            'https_flv' => "https://{$host}:{$httpsPort}/{$app}/{$stream}.live.flv",
            'wss_flv'   => "wss://{$host}:{$httpsPort}/{$app}/{$stream}.live.flv",
        ];
    }

    public function getPushUrl(int $id) : array
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Only push type can get push URL
        if ($proxy['type'] !== 'push') {
            throw new StreamProxyException(
                StreamProxyException::ERROR_PARAMETER,
                'Only push type proxies have push URL'
            );
        }

        // Get media server config
        $mediaServer = $this->getMediaServerService()->getMediaServer($proxy['media_server_id']);
        if (!$mediaServer) {
            throw StreamProxyException::MEDIA_SERVER_NOT_FOUND();
        }

        $host = $mediaServer['stream_ip'] ?? $mediaServer['host'];
        $rtmpPort = $mediaServer['rtmp_port'] ?? 1935;
        $rtspPort = $mediaServer['rtsp_port'] ?? 554;

        $app = $proxy['app'];
        $stream = $proxy['stream'];

        return [
            'rtmp'      => "rtmp://{$host}:{$rtmpPort}/{$app}/{$stream}",
            'rtsp'      => "rtsp://{$host}:{$rtspPort}/{$app}/{$stream}",
            'stream_id' => $stream,
            'app'       => $app,
            'tips'      => [
                'obs_rtmp' => "在OBS中设置推流地址时，服务器填写: rtmp://{$host}:{$rtmpPort}/{$app}，串流密钥填写: {$stream}",
                'ffmpeg'   => "使用FFmpeg推流: ffmpeg -re -i input.mp4 -c copy -f flv rtmp://{$host}:{$rtmpPort}/{$app}/{$stream}",
            ],
        ];
    }

    // ==================== Status Management ====================

    public function updateStatus(int $id, string $status, ?string $errorMessage = null) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            return false;
        }

        $updateFields = ['status' => $status];

        if ($status === 'online') {
            $updateFields['last_heartbeat_at'] = date('Y-m-d H:i:s');
            $updateFields['error_message'] = null;
        } else if ($status === 'error') {
            $updateFields['error_message'] = $errorMessage;
        }

        $result = $this->getStreamProxyDao()->update($id, $updateFields);

        return !empty($result);
    }

    public function healthCheck(int $id) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy || $proxy['status'] !== 'online') {
            return false;
        }

        // Get media server config
        $mediaServer = $this->getMediaServerService()->getMediaServer($proxy['media_server_id']);
        if (!$mediaServer) {
            return false;
        }

        // Check if stream is online
        $strategy = MediaServerStrategyFactory::create($mediaServer['type']);
        $isOnline = $strategy->isStreamOnline(
            $mediaServer,
            $proxy['app'],
            $proxy['stream'],
            $proxy['vhost']
        );

        if ($isOnline) {
            // Update heartbeat
            $this->getStreamProxyDao()->update($id, [
                'last_heartbeat_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } else {
            // Mark as offline
            $this->updateStatus($id, 'offline', 'Stream not found in media server');

            // Log offline event
            $this->addLog(
                $proxy['proxy_id'],
                'offline',
                "流代理 [{$proxy['name']}] 已离线",
                ['reason' => 'Stream not found in media server'],
                null,
                null,
                'warning'
            );

            return false;
        }
    }

    public function batchHealthCheck() : array
    {
        // Get all online proxies
        $proxies = $this->searchProxies(['status' => 'online'], [], 0, 1000);

        $results = [
            'total'   => count($proxies),
            'online'  => 0,
            'offline' => 0,
        ];

        foreach ($proxies as $proxy) {
            if ($this->healthCheck($proxy['id'])) {
                $results['online']++;
            } else {
                $results['offline']++;
            }
        }

        return $results;
    }

    public function autoReconnect() : array
    {
        // Get all offline/error proxies with auto-reconnect enabled
        $proxies = $this->searchProxies([
            'statuses'            => ['offline', 'error'],
            'enableAutoReconnect' => 1,
        ], [], 0, 1000);

        $results = [
            'total'   => count($proxies),
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
        ];

        foreach ($proxies as $proxy) {
            // Check if exceeded max retry count
            if ($proxy['current_retry_count'] >= $proxy['max_retry_count'] && $proxy['max_retry_count'] > 0) {
                $results['skipped']++;
                continue;
            }

            try {
                // Increment retry count
                $this->incrementRetryCount($proxy['id']);

                // Log reconnect attempt
                $this->addLog(
                    $proxy['proxy_id'],
                    'reconnect_attempt',
                    "尝试重新连接流代理 [{$proxy['name']}]",
                    ['retry_count' => $proxy['current_retry_count'] + 1, 'max_retry_count' => $proxy['max_retry_count']],
                    null,
                    null,
                    'info'
                );

                // Try to start
                $this->startProxy($proxy['id']);

                $results['success']++;

                // Increment total reconnect count
                $this->getStreamProxyDao()->update($proxy['id'], [
                    'total_reconnect_count' => $proxy['total_reconnect_count'] + 1,
                ]);

                // Log reconnect success
                $this->addLog(
                    $proxy['proxy_id'],
                    'reconnect_success',
                    "流代理 [{$proxy['name']}] 重新连接成功",
                    ['retry_count' => $proxy['current_retry_count'] + 1],
                    null,
                    null,
                    'info'
                );

                Log::channel('stream_proxy')->info("Auto reconnect success", [
                    'id'          => $proxy['id'],
                    'proxy_id'    => $proxy['proxy_id'],
                    'retry_count' => $proxy['current_retry_count'] + 1,
                ]);
            } catch (\Exception $e) {
                $results['failed']++;

                // Log reconnect failure
                $this->addLog(
                    $proxy['proxy_id'],
                    'reconnect_failed',
                    "流代理 [{$proxy['name']}] 重新连接失败",
                    ['retry_count' => $proxy['current_retry_count'] + 1, 'error' => $e->getMessage()],
                    null,
                    null,
                    'error'
                );

                Log::channel('stream_proxy')->error("Auto reconnect failed", [
                    'id'          => $proxy['id'],
                    'proxy_id'    => $proxy['proxy_id'],
                    'retry_count' => $proxy['current_retry_count'] + 1,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    // ==================== Recording Plan ====================

    public function bindRecordPlan(int $id, int $planId) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        // Verify record plan exists
        $plan = $this->getRecordPlanService()->getRecordPlan($planId);
        if (!$plan) {
            throw new StreamProxyException(
                StreamProxyException::CANNOT_BIND_PLAN,
                'Record plan not found'
            );
        }

        $result = $this->getStreamProxyDao()->update($id, [
            'record_plan_id' => $planId,
        ]);

        return !empty($result);
    }

    public function unbindRecordPlan(int $id) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            throw StreamProxyException::PROXY_NOT_FOUND();
        }

        $result = $this->getStreamProxyDao()->update($id, [
            'record_plan_id' => 0,
            'record_status'  => 0,
        ]);

        return !empty($result);
    }

    // ==================== Statistics ====================

    public function updateViewerCount(int $id, int $count) : bool
    {
        return !empty($this->getStreamProxyDao()->update($id, [
            'viewer_count' => $count,
        ]));
    }

    public function incrementRetryCount(int $id) : bool
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            return false;
        }

        return !empty($this->getStreamProxyDao()->update($id, [
            'current_retry_count' => $proxy['current_retry_count'] + 1,
        ]));
    }

    public function resetRetryCount(int $id) : bool
    {
        return !empty($this->getStreamProxyDao()->update($id, [
            'current_retry_count' => 0,
        ]));
    }

    // ==================== Helper Methods ====================

    private function generateUuid() : string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    // ==================== Logging ====================

    public function addLog(string $proxyId, string $eventType, string $message, ?array $details = null, ?int $userId = null, ?string $ipAddress = null, string $level = 'info') : array
    {
        $fields = [
            'proxy_id'   => $proxyId,
            'event_type' => $eventType,
            'level'      => $level,
            'message'    => $message,
            'details'    => $details,
            'user_id'    => $userId,
            'ip_address' => $ipAddress,
        ];

        return $this->getStreamProxyLogDao()->create($fields);
    }

    public function getProxyLogs(int $id, int $start = 0, int $limit = 100) : array
    {
        $proxy = $this->getProxy($id);
        if (!$proxy) {
            return [];
        }

        return $this->getStreamProxyLogDao()->findByProxyId($proxy['proxy_id'], ['created_at' => 'DESC'], $start, $limit);
    }

    public function searchLogs(array $conditions, array $orderBys, int $start, int $limit) : array
    {
        return $this->getStreamProxyLogDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countLogs(array $conditions) : int
    {
        return $this->getStreamProxyLogDao()->count($conditions);
    }

    public function cleanupOldLogs(int $daysToKeep = 30) : int
    {
        $date = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
        return $this->getStreamProxyLogDao()->deleteBeforeDate($date);
    }

    // ==================== Service Getters ====================

    protected function getStreamProxyDao() : StreamProxyDao|\CoreW\Dao\DaoProxy
    {
        return $this->createDao('StreamProxy:StreamProxyDao');
    }

    protected function getStreamProxyLogDao() : \CoreW\Business\StreamProxy\Dao\StreamProxyLogDao|\CoreW\Dao\DaoProxy
    {
        return $this->createDao('StreamProxy:StreamProxyLogDao');
    }

    protected function getMediaServerService() : MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    protected function getRecordPlanService() : RecordPlanService
    {
        return $this->createService('Record:RecordPlanService');
    }
}
