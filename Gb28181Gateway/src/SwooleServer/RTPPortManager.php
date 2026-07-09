<?php

namespace Gb28181Gateway\src\SwooleServer;

final class RTPPortManager
{
    private int $minPort;
    private int $maxPort;
    private int $currentPort;
    private CoLock $lock;
    /** @var array<int,string> */
    private array $allocatedPorts = [];

    public function __construct(int $minPort = 20002, int $maxPort = 30000)
    {
        $this->minPort = $minPort;
        $this->maxPort = $maxPort;
        $this->currentPort = $minPort;
        $this->lock = new CoLock();
    }

    public function allocate(string $channelId) : int
    {
        $this->lock->lock();
        try {
            $iterations = intdiv($this->maxPort - $this->minPort, 2);
            for ($i = 0; $i < $iterations; $i++) {
                if ($this->currentPort >= $this->maxPort) {
                    $this->currentPort = $this->minPort;
                }
                $port = $this->currentPort;
                $this->currentPort += 2; // RTP使用偶数端口

                if (!isset($this->allocatedPorts[$port])) {
                    if ($this->isPortAvailable($port)) {
                        $this->allocatedPorts[$port] = $channelId;
                        return $port;
                    }
                }
            }
            return 0; // 无可用端口
        } finally {
            $this->lock->unlock();
        }
    }

    public function release(int $port) : void
    {
        $this->lock->lock();
        try {
            unset($this->allocatedPorts[$port]);
        } finally {
            $this->lock->unlock();
        }
    }


    private function isPortAvailable(int $port) : bool
    {
        $sock = new CoSocket(AF_INET, SOCK_DGRAM, 0);
        $ok = @$sock->bind('0.0.0.0', $port);
        $sock->close();
        return $ok !== false;
    }

    //    private function isPortAvailable(int $port) : bool
    //    {
    //        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    //        if ($sock === false) return false;
    //        $ok = @socket_bind($sock, '0.0.0.0', $port);
    //        socket_close($sock);
    //        return $ok !== false;
    //    }
}