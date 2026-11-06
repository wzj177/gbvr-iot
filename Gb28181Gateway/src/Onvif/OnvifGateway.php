<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Onvif;

use Gb28181\GateWay\Swoole\CoroutineRedisTransport;
use Gb28181\GateWay\Swoole\ExoSip;
use Gb28181\GateWay\Transport\TransportInterface;

/**
 * ONVIF 网关编排器 —— 与 GB 网关同构的三条核心流程
 *
 *  - listen          ：WS-Discovery 组播监听（Hello）+ 周期 Probe（设备发现）
 *  - gateway-to-api  ：设备发现 / 命令执行结果 通过 HTTP hook 上报（onvif_* scene）
 *  - api-to-gateway  ：Redis 队列 onvif:commands:{priority|normal} 命令消费
 */
final class OnvifGateway
{
    private array $config;

    private WsDiscovery $discovery;

    private $logger;

    private bool $running = false;

    public function __construct(array $config, $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->discovery = new WsDiscovery($config, $logger);
    }

    public function start() : void
    {
        $this->running = true;

        // ---- listen：发现监听 + 周期 Probe ----
        if ($this->config['discovery_enabled'] ?? true) {
            $bindIp = $this->config['bind_ip'] ?? '0.0.0.0';
            if (!$this->discovery->start($bindIp)) {
                $this->log('WS-Discovery 启动失败（组播端口被占用？），仅命令模式可用', 'ERROR');
            } else {
                \Swoole\Coroutine::create(function () {
                    $this->listenLoop();
                });
                \Swoole\Coroutine::create(function () {
                    $this->probeLoop();
                });
            }
        }

        // ---- api-to-gateway：命令消费 ----
        \Swoole\Coroutine::create(function () {
            $this->commandLoop();
        });

        $this->log('ONVIF gateway started: discovery=' . (($this->config['discovery_enabled'] ?? true) ? 'on' : 'off')
            . ', queue=' . (($this->config['redis']['queue_name'] ?? 'onvif:commands')));
    }

    public function stop() : void
    {
        $this->running = false;
        $this->discovery->stop();
    }

    // =====================================================================
    // listen：发现循环
    // =====================================================================

    private function listenLoop() : void
    {
        while ($this->running) {
            try {
                foreach ($this->discovery->receive(1.0) as $device) {
                    // gateway-to-api：新设备发现上报
                    $this->postScene('onvif_device_found', [
                        'uuid'      => $device['uuid'],
                        'ip'        => $device['ip'],
                        'endpoint'  => $device['endpoint'],
                        'xaddrs'    => $device['xaddrs'],
                        'types'     => $device['types'] ?? '',
                        'scopes'    => $device['scopes'] ?? '',
                        'timestamp' => time(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->log('listenLoop error: ' . $e->getMessage(), 'ERROR');
                \Swoole\Coroutine::sleep(1);
            }
        }
    }

    private function probeLoop() : void
    {
        $interval = max(10, (int)($this->config['probe_interval'] ?? 60));
        $types    = (array)($this->config['probe_types'] ?? ['dn:NetworkVideoTransmitter']);
        while ($this->running) {
            $this->discovery->probe($types);
            \Swoole\Coroutine::sleep($interval);
        }
    }

    // =====================================================================
    // api-to-gateway：命令消费
    // =====================================================================

    private function commandLoop() : void
    {
        $redisConfig = $this->config['redis'] ?? [];
        $baseQueue   = $redisConfig['queue_name'] ?? 'onvif:commands';
        $gatewayId   = $this->config['gateway_id'] ?? '';
        $suffix      = $gatewayId !== '' ? ':' . $gatewayId : '';
        $queueKeys   = [$baseQueue . ':priority' . $suffix, $baseQueue . ':normal' . $suffix];

        /** @var TransportInterface|null $transport */
        $transport = null;
        $this->log('command consumer started: queues=' . implode(', ', $queueKeys));

        while ($this->running) {
            try {
                if ($transport === null) {
                    $transport = new CoroutineRedisTransport($redisConfig);
                    if (!$transport->connect()) {
                        $transport = null;
                        \Swoole\Coroutine::sleep(2);
                        continue;
                    }
                }
                $result = $transport->pop($queueKeys, 1);
                if ($result === null) {
                    if ($transport !== null && !$transport->isHealthy()) {
                        $transport->close();
                        $transport = null;
                    }
                    continue;
                }
                [$queueKey, $message] = $result;
                \Swoole\Coroutine::create(function () use ($message, $queueKey) {
                    $this->handleCommand($message, $queueKey);
                });
            } catch (\Throwable $e) {
                $this->log('commandLoop error: ' . $e->getMessage(), 'ERROR');
                $transport?->close();
                $transport = null;
                \Swoole\Coroutine::sleep(1);
            }
        }
        $transport?->close();
    }

    private function handleCommand(string $message, string $queueKey) : void
    {
        $cmd = json_decode($message, true);
        if (!is_array($cmd)) {
            $this->log("invalid JSON command from {$queueKey}", 'WARNING');
            return;
        }
        $action    = $cmd['action'] ?? 'unknown';
        $requestId = $cmd['request_id'] ?? 'unknown';

        // 命令过期检查（与 GB CommandSubscriber 一致）
        if (isset($cmd['timestamp'])) {
            $age = time() - (int)$cmd['timestamp'];
            if ($age > 60 || $age < -30) {
                $this->log("command expired, ignoring. action={$action}, age={$age}s", 'WARNING');
                return;
            }
        }

        $this->log("command received: action={$action}, req_id={$requestId}, queue={$queueKey}");
        $params = $cmd['params'] ?? [];

        try {
            $data = $this->dispatch($action, $params);
            $this->postScene('onvif_cmd_after', [
                'request_id' => $requestId,
                'action'     => $action,
                'success'    => true,
                'data'       => $data,
                'timestamp'  => time(),
            ]);
        } catch (\Throwable $e) {
            $this->log("command failed: action={$action}, error=" . $e->getMessage(), 'ERROR');
            $this->postScene('onvif_cmd_after', [
                'request_id' => $requestId,
                'action'     => $action,
                'success'    => false,
                'error'      => $e->getMessage(),
                'timestamp'  => time(),
            ]);
        }
    }

    /**
     * 命令分发（每命令独立 OnvifClient，凭据可由 params 覆盖默认）
     * @return array 命令结果数据
     */
    private function dispatch(string $action, array $params) : array
    {
        switch ($action) {
            case 'onvif_discover':
                return $this->actionDiscover($params);

            case 'onvif_get_device_info':
                return $this->client($params)->getDeviceInformation();

            case 'onvif_get_capabilities':
                return $this->client($params)->getCapabilities(!empty($params['refresh']));

            case 'onvif_get_profiles':
                return ['profiles' => $this->client($params)->getProfiles()];

            case 'onvif_get_stream_uri':
                return $this->client($params)->getStreamUri(
                    (string)($params['profile_token'] ?? ''),
                    (string)($params['protocol'] ?? 'RTSP')
                );

            case 'onvif_get_snapshot_uri':
                return $this->client($params)->getSnapshotUri((string)($params['profile_token'] ?? ''));

            case 'onvif_get_presets':
                return ['presets' => $this->client($params)->ptzGetPresets((string)($params['profile_token'] ?? ''))];

            case 'onvif_ptz_control':
                return $this->actionPtz($params);

            case 'onvif_reboot':
                $this->client($params)->reboot();
                return ['rebooting' => true];

            default:
                throw new \RuntimeException("Unsupported ONVIF action: {$action}");
        }
    }

    /** 主动发现：发 Probe 并在时间窗内收集应答（即时返回窗口内已见的设备） */
    private function actionDiscover(array $params) : array
    {
        $types = (array)($params['types'] ?? ['dn:NetworkVideoTransmitter']);
        $wait  = min(10.0, max(0.5, (float)($params['wait'] ?? 2.0)));
        $this->discovery->probe($types);
        $deadline = microtime(true) + $wait;
        $devices  = [];
        while (microtime(true) < $deadline) {
            foreach ($this->discovery->receive(0.2) as $dev) {
                $devices[$dev['uuid']] = $dev;
            }
        }
        return ['devices' => array_values($devices)];
    }

    private function actionPtz(array $params) : array
    {
        $client  = $this->client($params);
        $token   = (string)($params['profile_token'] ?? '');
        $command = (string)($params['command'] ?? '');
        switch ($command) {
            case 'continuous_move':
                $client->ptzContinuousMove($token,
                    (float)($params['pan'] ?? 0), (float)($params['tilt'] ?? 0), (float)($params['zoom'] ?? 0));
                return ['moved' => true];
            case 'stop':
                $client->ptzStop($token, !($params['pan_tilt'] ?? true) ? false : true, !($params['zoom'] ?? true) ? false : true);
                return ['stopped' => true];
            case 'absolute_move':
                $client->ptzAbsoluteMove($token,
                    (float)($params['pan'] ?? 0), (float)($params['tilt'] ?? 0), (float)($params['zoom'] ?? 0),
                    (float)($params['speed'] ?? 0.5));
                return ['moved' => true];
            case 'goto_preset':
                $client->ptzGotoPreset($token, (string)($params['preset_token'] ?? ''), (float)($params['speed'] ?? 0.5));
                return ['goto' => $params['preset_token'] ?? ''];
            case 'set_preset':
                $presetToken = $client->ptzSetPreset($token, (string)($params['preset_name'] ?? 'preset'));
                return ['preset_token' => $presetToken];
            default:
                throw new \RuntimeException("Unsupported PTZ command: {$command}");
        }
    }

    /** @return OnvifClient */
    private function client(array $params) : OnvifClient
    {
        $ip = (string)($params['ip'] ?? '');
        $endpoint = (string)($params['endpoint'] ?? '');
        if ($endpoint === '' && $ip !== '') {
            $port = (int)($params['port'] ?? 80);
            $endpoint = "http://{$ip}" . ($port !== 80 ? ":{$port}" : '') . '/onvif/device_service';
        }
        if ($endpoint === '') {
            throw new \RuntimeException('missing ip/endpoint param');
        }
        return new OnvifClient(
            $endpoint,
            (string)($params['username'] ?? ($this->config['default_username'] ?? 'admin')),
            (string)($params['password'] ?? ($this->config['default_password'] ?? '')),
            (int)($this->config['http_timeout'] ?? 5),
            $this->logger
        );
    }

    // =====================================================================
    // gateway-to-api：hook 上报（与 GB postTask 相同的 scene 化协议）
    // =====================================================================

    private function postScene(string $scene, array $body) : void
    {
        $url = $this->config['api']['hock_url'] ?? '';
        if ($url === '') {
            return;
        }
        $payload = json_encode(['scene' => $scene, 'body' => $body], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Token: ' . ($this->config['api']['token'] ?? ''),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        curl_close($ch);
        if ($errno) {
            $this->log("postScene({$scene}) failed: curl errno={$errno}", 'ERROR');
            return;
        }
        $this->log("postScene({$scene}) ok: " . substr((string)$result, 0, 200), 'DEBUG');
    }

    private function log(string $msg, string $level = 'INFO') : void
    {
        if ($this->logger !== null) {
            $m = strtolower($level === 'WARNING' ? 'error' : $level);
            if (method_exists($this->logger, $m)) {
                $this->logger->{$m}("[OnvifGateway] {$msg}");
            }
            return;
        }
        echo '[' . date('Y-m-d H:i:s') . "] [OnvifGateway] [{$level}] {$msg}\n";
    }
}
