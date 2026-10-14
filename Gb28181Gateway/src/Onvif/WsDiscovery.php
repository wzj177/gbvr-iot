<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Onvif;

use Swoole\Coroutine;
use Swoole\Coroutine\Socket as CoSocket;

/**
 * WS-Discovery 发现器（listen 流程）
 *
 * UDP 组播 239.255.255.250:3702：
 *  - 周期发送 Probe，摄像头以单播 ProbeMatch 应答（含 XAddrs 设备服务地址）
 *  - 监听设备上线的 Hello 组播
 * 组播加入失败时仍可工作（Probe 应答是单播回源地址的，仅丢失 Hello）
 */
final class WsDiscovery
{
    public const MULTICAST_ADDR = '239.255.255.250';

    public const MULTICAST_PORT = 3702;

    private ?CoSocket $sock = null;

    private bool $running = false;

    /** @var array<string, array> 已发现设备 uuid => info（含 last_seen） */
    private array $devices = [];

    private int $dedupTtl;

    private $logger;

    public function __construct(array $config = [], $logger = null)
    {
        $this->dedupTtl = (int)($config['dedup_ttl'] ?? 300);
        $this->logger   = $logger;
    }

    public function start(string $bindIp = '0.0.0.0') : bool
    {
        $sock = new CoSocket(AF_INET, SOCK_DGRAM);
        $sock->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        if (!$sock->bind($bindIp, self::MULTICAST_PORT)) {
            $this->log("WS-Discovery bind {$bindIp}:" . self::MULTICAST_PORT . " failed: {$sock->errMsg}", 'ERROR');
            $sock->close();
            return false;
        }
        // 加入组播组（Linux: IP_ADD_MEMBERSHIP=35；无 ext-sockets 常量时按 Linux 取值，docker 环境适用）
        $membership = defined('IP_ADD_MEMBERSHIP') ? IP_ADD_MEMBERSHIP : 35;
        $joined = $sock->setOption(getprotobyname('ip') ?: 0, $membership, [
            'group'     => self::MULTICAST_ADDR,
            'interface' => $bindIp === '0.0.0.0' ? '0.0.0.0' : $bindIp,
        ]);
        if (!$joined) {
            $this->log('WS-Discovery multicast join failed（仍可单播收 ProbeMatch 应答，丢失 Hello）: ' . ($sock->errMsg ?? ''), 'WARNING');
        }

        $this->sock    = $sock;
        $this->running = true;
        return true;
    }

    public function stop() : void
    {
        $this->running = false;
        $this->sock?->close();
        $this->sock = null;
    }

    /**
     * 发送 Probe（可指定类型/范围过滤）
     */
    public function probe(array $types = ['dn:NetworkVideoTransmitter'], array $scopes = [], ?float $timeout = null) : void
    {
        if (!$this->sock) {
            return;
        }
        $uuid = 'urn:uuid:' . $this->uuid4();
        $now  = gmdate('Y-m-d\TH:i:s.u\Z');
        $typesXml = implode(' ', array_map(fn($t) => htmlspecialchars($t, ENT_XML1), $types));
        $scopesXml = $scopes !== [] ? '<d:Scopes>' . htmlspecialchars(implode(' ', $scopes), ENT_XML1) . '</d:Scopes>' : '';

        $msg = '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n"
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing" xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" xmlns:dn="http://www.onvif.org/ver10/network/wsdl">' . "\r\n"
            . '<s:Header><a:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</a:Action><a:MessageID>' . $uuid . '</a:MessageID><a:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</a:To></s:Header>' . "\r\n"
            . '<s:Body><d:Probe>' . $scopesXml . '<d:Types>' . $typesXml . '</d:Types></d:Probe></s:Body>' . "\r\n"
            . '</s:Envelope>' . "\r\n";
        // Probe 发往组播地址；设备按 WS-Discovery 规范单播回源应答
        $this->sock->sendto(self::MULTICAST_ADDR, self::MULTICAST_PORT, $msg);
        $this->log('Probe sent: ' . $typesXml, 'DEBUG');
    }

    /**
     * 接收循环（协程内调用）：解析 ProbeMatch/Hello，返回新发现（或 TTL 过期后重新上报）的设备列表
     * @param float $timeout 单次 recv 超时（秒）
     * @return array[] 本次上报的设备 [{uuid, ip, xaddrs, last_seen, message_id}]
     */
    public function receive(float $timeout = 1.0) : array
    {
        if (!$this->sock || !$this->running) {
            return [];
        }
        $peer = [];
        $data = $this->sock->recvfrom($peer, $timeout);
        if ($data === false || $data === '') {
            return [];
        }
        $devices = $this->parseDiscoveryMessage($data, (string)($peer['address'] ?? ''));
        $fresh   = [];
        $now     = time();
        foreach ($devices as $dev) {
            $uuid = $dev['uuid'];
            $seen = $this->devices[$uuid] ?? null;
            if ($seen && ($now - $seen['reported_at']) < $this->dedupTtl) {
                $this->devices[$uuid]['last_seen'] = $now;
                continue;   // 去重窗口内，仅刷新时间
            }
            $dev['reported_at'] = $now;
            $dev['last_seen']   = $now;
            $this->devices[$uuid] = $dev;
            $fresh[] = $dev;
        }
        $this->gcDevices();
        return $fresh;
    }

    /**
     * 解析 ProbeMatch / Hello 报文中的设备信息
     * @return array[]
     */
    public function parseDiscoveryMessage(string $xml, string $srcIp) : array
    {
        $clean = preg_replace('/(<\?xml[^>]*\?>)/', '$1', $xml) ?? $xml;
        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($clean);
        libxml_clear_errors();
        if (!$doc) {
            return [];
        }
        $doc->registerXPathNamespace('s', 'http://www.w3.org/2003/05/soap-envelope');
        $doc->registerXPathNamespace('a', 'http://schemas.xmlsoap.org/ws/2004/08/addressing');
        $doc->registerXPathNamespace('d', 'http://schemas.xmlsoap.org/ws/2005/04/discovery');

        $action = (string)($doc->xpath('//a:Action')[0] ?? '');
        if (!str_contains($action, 'ProbeMatch') && !str_contains($action, 'Hello') && !str_contains($action, 'ResolveMatch')) {
            return [];
        }

        $out = [];
        foreach ($doc->xpath('//d:ProbeMatch|//d:Hello|//d:ResolveMatch') as $match) {
            /** @var \SimpleXMLElement $match */
            $match->registerXPathNamespace('a', 'http://schemas.xmlsoap.org/ws/2004/08/addressing');
            $match->registerXPathNamespace('d', 'http://schemas.xmlsoap.org/ws/2005/04/discovery');
            $uuid  = trim((string)($match->xpath('.//a:Address')[0] ?? ''));
            $xaddr = trim((string)($match->xpath('.//d:XAddrs')[0] ?? ''));
            if ($uuid === '' || $xaddr === '') {
                continue;
            }
            // XAddrs 可能多条空格分隔，取第一条；提取 IP
            $first = preg_split('/\s+/', $xaddr)[0];
            $ip    = $srcIp;
            if (preg_match('#http://([\d.]+):?#', $first, $m)) {
                $ip = $m[1];
            }
            $out[] = [
                'uuid'       => $uuid,
                'ip'         => $ip,
                'xaddrs'     => $xaddr,
                'endpoint'   => $first,
                'types'      => trim((string)($match->xpath('.//d:Types')[0] ?? '')),
                'scopes'     => trim((string)($match->xpath('.//d:Scopes')[0] ?? '')),
                'message_id' => trim((string)($doc->xpath('//a:MessageID')[0] ?? '')),
            ];
        }
        return $out;
    }

    /** 清理长期未见（离线）设备 */
    private function gcDevices() : void
    {
        $now = time();
        $offline = (int)($this->dedupTtl * 3);
        foreach ($this->devices as $uuid => $d) {
            if ($now - $d['last_seen'] > $offline) {
                unset($this->devices[$uuid]);
            }
        }
    }

    private function uuid4() : string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function log(string $msg, string $level = 'INFO') : void
    {
        if ($this->logger !== null) {
            $m = strtolower($level === 'WARNING' ? 'error' : $level);
            if (method_exists($this->logger, $m)) {
                $this->logger->{$m}("[WsDiscovery] {$msg}");
            }
            return;
        }
        echo '[' . date('Y-m-d H:i:s') . "] [WsDiscovery] [{$level}] {$msg}\n";
    }
}
