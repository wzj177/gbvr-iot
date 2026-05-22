<?php

namespace Gb28181\GateWay\Media;

use Gb28181\GateWay\Device\Device;

class MediaSession
{
    public Device $device;
    public int $did;           // Dialog ID
    public int $tid;           // Transaction ID
    public string $targetIp = '';
    public int $targetPort = 0;
    public int $localPort = 0;
    public string $ssrc = '';
    public string $channelId = '';
    public string $playMode = 'realtime'; // realtime, playback, download

    public bool $used = false;
    public bool $paused = false;

    public function __construct(array $config)
    {
        $this->device = $config['device'];
        $this->did = $config['did'];
        $this->tid = $config['tid'];
    }

    /**
     * 开始推流
     */
    public function start() : void
    {
        if ($this->used) {
            return;
        }

        $this->used = true;
        $this->log("开始推流 (did={$this->did})", 'INFO');

        // TODO: 实际的推流逻辑
        // 根据 playMode 决定是实时流还是回放
    }

    /**
     * 停止推流
     */
    public function stop() : void
    {
        if (!$this->used) {
            return;
        }

        $this->used = false;
        $this->log("停止推流 (did={$this->did})", 'INFO');

        // TODO: 停止推流
    }

    /**
     * 暂停/继续
     */
    public function pause(bool $pause) : void
    {
        $this->paused = $pause;
        $this->log(($pause ? "暂停" : "继续") . "推流 (did={$this->did})", 'INFO');

        // TODO: 暂停/继续推流
    }

    private function log(string $message, string $level = 'INFO') : void
    {
    }
}