<?php

namespace Gb28181Gateway\src\SwooleServer;

final class Device
{
    public string $deviceId;
    public ?string $ip;
    public ?int $port;
    public bool $registered = false;
    public ?string $registerTime = null;
    /** @var Channel[] */
    public array $channels = [];
    public ?string $callId = null;
    public ?string $fromTag = null;
    public ?string $toTag = null;
    public int $lastRegisterTime = 0;   // 毫秒时间戳
    public int $lastKeepaliveTime = 0;  // 毫秒时间戳
    public string $name = '';
    public string $manufacturer = '';
    public string $model = '';
    public string $firmwareVersion = '';

    public function __construct(string $deviceId, ?string $ip = null, ?int $port = null)
    {
        $this->deviceId = $deviceId;
        $this->ip = $ip;
        $this->port = $port;
    }
}