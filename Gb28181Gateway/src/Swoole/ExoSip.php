<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Swoole;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel as CoChannel;
use Swoole\Coroutine\Socket as CoSocket;

/**
 * ExoSip —— php-exosip C 扩展的 Swoole 6.x 协程版等价物
 *
 * 本类带命名空间，由入口通过 class_alias() 映射为全局 \ExoSip，
 * 业务层（GB28181Handler / CommandDispatcher 等）的 `\ExoSip`/`\SipEvent` 类型标注零改动。
 *
 * 自解析/构建 SIP 报文（不依赖协议框架），
 * 在其基础上补齐 eXosip 原生提供、rebucca 版缺失的协议能力：
 *  - 入站请求顶层 Via 注入 received/rport（eXosip 标准行为，GB28181Handler 依赖它取设备实际地址）
 *  - Dialog 表（Call-ID + From/To tag 关联），支撑 ACK/BYE/INFO/NOTIFY/订阅续订等在对话内请求
 *  - 出站事务 UDP 重传（RFC 3261 Timer B/F）与超时合成 408 事件
 *  - 出站请求 401/407 Digest 挑战重试（UAC）
 *  - final 响应自动补 To tag
 *
 * 进程模型等价映射：
 *  - Worker（事件串行回调）   → dispatchQ 队列 + 单个分发协程（业务回调串行，等价单 Worker）
 *  - Task 进程               → task_worker_num 个任务协程（onTask 执行 HTTP hook，onTaskFinish 回分发协程）
 *  - LongTask 进程           → startLongTask() 直接起协程（协程化 Redis BLPOP 不阻塞调度器）
 *  - onTimer                 → 定时协程
 */
class ExoSip
{
    // ==================== 事件回调（业务层赋值） ====================
    public $onRegister;
    public $onInvite;
    public $onAck;
    public $onBye;
    public $onCancel;
    public $onOptions;
    public $onMessage;
    public $onInfo;
    public $onUpdate;
    public $onPrack;
    public $onRefer;
    public $onSubscribe;
    public $onNotify;
    public $onPublish;
    public $onResponse;
    public $onTimeout;
    public $onError;
    public $onConnect;
    public $onClose;
    public $onTask;
    public $onTaskFinish;
    public $onTimer;
    public $onPipeMessage;
    public $onWorkerStart;

    /** @var object|null 注入日志器（需提供 debug/info/error 方法，SimpleLogger 兼容） */
    public $logger;

    private array $cfg = [
        'sipId'          => '34020000002000000001',
        'sipRealm'       => '3402000000',
        'ip'             => '0.0.0.0',
        'port'           => 15060,
        'mode'           => 'UDP',
        'ua'             => 'PHP GB28181 Server/1.0',
        'public_ip'      => '',
        'username'       => '',
        'password'       => '',
        'debug'          => false,
        'task_worker_num'    => 4,
        'long_task_worker_num' => 1,
        'timer_interval' => 60,   // 秒（gb28181.php 的 timer_interval 单位是秒）
        'pid_file'       => '',
    ];

    /** @var string 对外地址（Via/Contact 用）：public_ip 优先，其次本机非回环 IP */
    private string $viaHost = '127.0.0.1';

    private ?CoSocket $udpSock   = null;
    private ?CoSocket $tcpListen = null;

    /** @var array<int, array{sock: CoSocket, addr: array, rbuf: string}> 入站 TCP 连接 */
    private array $tcpConns = [];

    /** @var array<string, CoSocket> 出站 TCP 客户端连接 "ip:port" => socket */
    private array $tcpOut = [];

    private int $idSeq = 0;

    /** @var array<int, array> 入站事务 tid => ['msg'=>parsed, 'addr'=>, 'connId'=>?, 'cid'=>?] */
    private array $inTxns = [];

    /** @var array<int, array> 呼叫腿 cid => leg（dir in/out, callid, tags, cseq, raw, addr...） */
    private array $calls = [];

    /** @var array<string, array<int, int>> 出站事务索引 "callidStr|METHOD" => [cid,...] */
    private array $outIndex = [];

    /** @var array<int, array> 对话 did => dialog */
    private array $dialogs = [];

    /** @var array<string, array<int, int>> 对话索引 callidStr => [did,...]（tag 匹配无序对） */
    private array $dialogIndex = [];

    /** @var array<int, array> 出站订阅 sid => ['did'=>, 'event'=>, 'expires'=>, 'device_id'=>] */
    private array $subs = [];

    /** @var array<string, array> 设备注册地址 deviceId => ['ip'=>,'port'=>,'connId'=>?]（sendMessage 目标解析用） */
    private array $regs = [];

    private ?CoChannel $dispatchQ = null;
    private ?CoChannel $taskQ     = null;

    public bool $running = false;

    private int $cseqSeq = 1;

    public function __construct(array $config = [])
    {
        foreach ($this->cfg as $k => $_) {
            if (array_key_exists($k, $config) && $config[$k] !== null && $config[$k] !== '') {
                $this->cfg[$k] = $config[$k];
            }
        }
        $this->logger = $config['logger'] ?? null;
        $this->cfg['mode']           = strtoupper((string)$this->cfg['mode']);
        $this->cfg['task_worker_num'] = max(1, (int)$this->cfg['task_worker_num']);

        $this->viaHost = $this->detectLocalIp();

        // 协程化阻塞调用：curl（hook 回调）、sleep —— 业务层 CurlTrait 无需改动即非阻塞
        $flags = 0;
        foreach (['SWOOLE_HOOK_TCP', 'SWOOLE_HOOK_UDP', 'SWOOLE_HOOK_UNIX', 'SWOOLE_HOOK_CURL', 'SWOOLE_HOOK_SLEEP', 'SWOOLE_HOOK_BLOCKING_FUNCTION'] as $c) {
            if (defined($c)) {
                $flags |= constant($c);
            }
        }
        if ($flags) {
            Coroutine::set(['hook_flags' => $flags]);
        }
    }

    public function getConfig(?string $key = null)
    {
        return $key === null ? $this->cfg : ($this->cfg[$key] ?? null);
    }

    public function getStats() : array
    {
        return [
            'running'        => $this->running,
            'devices'        => count($this->regs),
            'in_txns'        => count($this->inTxns),
            'calls'          => count($this->calls),
            'dialogs'        => count($this->dialogs),
            'subscriptions'  => count($this->subs),
            'tcp_connections' => count($this->tcpConns) + count($this->tcpOut),
        ];
    }

    // =====================================================================
    // 启动 / 停止
    // =====================================================================

    public function run() : bool
    {
        if (Coroutine::getCid() >= 0) {
            $this->boot();
            return true;
        }
        self::coroutineRun(function () {
            $this->boot();
        });
        return true;
    }

    /**
     * 跨版本协程容器启动：Swoole 6.6+ 用 Coroutine::run，早期 6.x/4.x 无此方法时退回 Scheduler
     */
    public static function coroutineRun(callable $fn) : void
    {
        if (method_exists(Coroutine::class, 'run')) {
            Coroutine::run($fn);
            return;
        }
        $scheduler = new \Swoole\Coroutine\Scheduler();
        $scheduler->add($fn);
        $scheduler->start();
    }

    private function boot() : void
    {
        $this->running   = true;
        $this->dispatchQ = new CoChannel(65536);
        $this->taskQ     = new CoChannel(65536);

        // Worker 启动事件（业务在此 startLongTask 注册命令订阅等）
        $this->fireSync($this->onWorkerStart, $this);

        // 串行业务分发协程（等价单 Worker：SIP 事件 / onTaskFinish / onPipeMessage / tick 串行）
        Coroutine::create(function () {
            while ($this->running) {
                $fn = $this->dispatchQ->pop(1.0);
                if ($fn instanceof \Closure) {
                    try {
                        $fn();
                    } catch (\Throwable $e) {
                        $this->log('dispatch error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'ERROR');
                    }
                }
            }
        });

        // Task 协程池（等价 task 进程：执行 onTask 的 HTTP 回调，完成后 onTaskFinish 回分发协程）
        for ($i = 0; $i < $this->cfg['task_worker_num']; $i++) {
            Coroutine::create(function () {
                while ($this->running) {
                    $item = $this->taskQ->pop(1.0);
                    if (!is_array($item)) {
                        continue;
                    }
                    [$taskId, $data] = $item;
                    $result = null;
                    try {
                        $result = $this->fireSync($this->onTask, $taskId, $data);
                    } catch (\Throwable $e) {
                        $this->log("onTask #{$taskId} error: " . $e->getMessage(), 'ERROR');
                    }
                    $this->dispatch(function () use ($taskId, $result) {
                        $this->fireSync($this->onTaskFinish, $taskId, $result);
                    });
                }
            });
        }

        // 定时协程（等价 onTimer）
        $interval = max(1, (int)$this->cfg['timer_interval']);
        Coroutine::create(function () use ($interval) {
            while ($this->running) {
                Coroutine::sleep($interval);
                if (!$this->running) {
                    return;
                }
                $this->dispatch(function () {
                    $this->fireSync($this->onTimer);
                });
            }
        });

        // 监听 socket
        if (in_array($this->cfg['mode'], ['UDP', 'ALL'], true)) {
            $sock = new CoSocket(AF_INET, SOCK_DGRAM);
            $sock->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            if (!$sock->bind($this->cfg['ip'], (int)$this->cfg['port'])) {
                throw new \RuntimeException("UDP bind {$this->cfg['ip']}:{$this->cfg['port']} failed: {$sock->errMsg}");
            }
            $this->udpSock = $sock;
            $this->log("SIP UDP listening on {$this->cfg['ip']}:{$this->cfg['port']}");
            Coroutine::create(function () {
                $this->udpRecvLoop();
            });
        }
        if (in_array($this->cfg['mode'], ['TCP', 'ALL'], true)) {
            $ls = new CoSocket(AF_INET, SOCK_STREAM);
            $ls->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            if (!$ls->bind($this->cfg['ip'], (int)$this->cfg['port']) || !$ls->listen(128)) {
                throw new \RuntimeException("TCP listen {$this->cfg['ip']}:{$this->cfg['port']} failed: {$ls->errMsg}");
            }
            $this->tcpListen = $ls;
            $this->log("SIP TCP listening on {$this->cfg['ip']}:{$this->cfg['port']}");
            Coroutine::create(function () {
                $this->tcpAcceptLoop();
            });
        }

        $this->log('Swoole ExoSip engine started: mode=' . $this->cfg['mode'] . ', viaHost=' . $this->viaHost);
    }

    public function stop() : bool
    {
        $this->running = false;
        $this->udpSock?->close();
        $this->tcpListen?->close();
        foreach ($this->tcpConns as $c) {
            $c['sock']->close();
        }
        foreach ($this->tcpOut as $s) {
            $s->close();
        }
        $this->tcpConns = [];
        $this->tcpOut   = [];
        $this->dispatchQ?->close();
        $this->taskQ?->close();
        return true;
    }

    public function isRunning() : bool
    {
        return $this->running;
    }

    // =====================================================================
    // Task / LongTask / Pipe（进程模型协程等价物）
    // =====================================================================

    public function addTask(array $data) : int
    {
        $taskId = $this->nextId();
        $this->taskQ->push([$taskId, $data]);
        return $taskId;
    }

    /** LongTask 协程等价：回调内部自行循环（CommandSubscriber::run） */
    public function startLongTask(callable $callback) : bool
    {
        Coroutine::create(function () use ($callback) {
            try {
                $callback($this);
            } catch (\Throwable $e) {
                $this->log('longtask error: ' . $e->getMessage(), 'ERROR');
            }
        });
        return true;
    }

    /** LongTask → Worker（协程版：投递到分发协程串行执行 onPipeMessage） */
    public function sendToWorker($data) : bool
    {
        $this->dispatch(function () use ($data) {
            $this->fireSync($this->onPipeMessage, $data);
        });
        return true;
    }

    // =====================================================================
    // 出站：MESSAGE / INVITE / BYE / ACK / INFO / SUBSCRIBE
    // =====================================================================

    /**
     * 发送 SIP MESSAGE（GB28181 XML 查询/控制）
     *
     * @param string $to           目标 URI：sip:device@ip:port 或 sip:device@realm（后者查注册表）
     * @param string $message      XML 正文
     * @param string|null $contentType
     * @return int|false           call_id 句柄；解析目标失败返回 false
     */
    public function sendMessage(string $to, string $message, ?string $contentType = null)
    {
        $target = $this->resolveTarget($to);
        if (!$target) {
            return false;
        }
        [$user, $ip, $port, $connId] = $target;

        $cid = $this->nextId();
        $leg = $this->newOutLeg('MESSAGE', $cid, $user, $ip, $port, $connId);
        $raw = SipCodec::buildRequest(
            'MESSAGE',
            "sip:{$user}@{$ip}:{$port}",
            "<sip:{$this->cfg['sipId']}@{$this->cfg['sipRealm']}>;tag={$leg['fromTag']}",
            "<sip:{$user}@{$this->cfg['sipRealm']}>",
            $leg['callid'],
            $leg['cseq'],
            $this->viaHost,
            (int)$this->cfg['port'],
            $this->transportName(),
            $contentType ? ['Content-Type' => $contentType] : [],
            $message,
            (string)$this->cfg['ua']
        );
        $leg['raw'] = $raw;
        $this->calls[$cid] = $leg;
        $this->indexOut($leg);

        if (!$this->sendRaw($raw, [$ip, $port], $connId)) {
            unset($this->calls[$cid]);
            return false;
        }
        $this->startRetransmit($cid, 4.0);   // 非 INVITE：重传至 T2=4s（RFC 3261 Timer F）
        return $cid;
    }

    /**
     * 发送 INVITE（点播/回放/下载/语音）
     *
     * @return int|false call_id 句柄
     */
    public function sendInvite(string $toUri, string $sdp, ?array $headers = null)
    {
        $target = $this->resolveTarget($toUri);
        if (!$target) {
            return false;
        }
        [$user, $ip, $port, $connId] = $target;

        $cid = $this->nextId();
        $leg = $this->newOutLeg('INVITE', $cid, $user, $ip, $port, $connId);
        $hdrs = $headers ?? [];
        $hdrs['Content-Type'] = $hdrs['Content-Type'] ?? 'application/sdp';
        $raw = SipCodec::buildRequest(
            'INVITE',
            "sip:{$user}@{$ip}:{$port}",
            "<sip:{$this->cfg['sipId']}@{$this->cfg['sipRealm']}>;tag={$leg['fromTag']}",
            "<sip:{$user}@{$this->cfg['sipRealm']}>",
            $leg['callid'],
            $leg['cseq'],
            $this->viaHost,
            (int)$this->cfg['port'],
            $this->transportName(),
            $hdrs,
            $sdp,
            (string)$this->cfg['ua']
        );
        $leg['raw'] = $raw;
        $this->calls[$cid] = $leg;
        $this->indexOut($leg);

        if (!$this->sendRaw($raw, [$ip, $port], $connId)) {
            unset($this->calls[$cid]);
            return false;
        }
        $this->startRetransmit($cid, 32.0);  // INVITE：Timer B = 64*T1 ≈ 32s
        return $cid;
    }

    public function sendBye(int $callId, int $dialogId = -1) : bool
    {
        $dialog = null;
        if ($dialogId > 0) {
            $dialog = $this->dialogs[$dialogId] ?? null;
        }
        if (!$dialog) {
            foreach ($this->dialogs as $did => $d) {
                if ($d['cid'] === $callId) {
                    $dialog   = $d;
                    $dialogId = $did;
                    break;
                }
            }
        }
        if (!$dialog) {
            return false;
        }

        $ok = $this->sendInDialogRequest($dialogId, 'BYE', '', '', []);
        $this->removeDialog($dialogId);
        return $ok;
    }

    public function sendAck(int $dialogId) : bool
    {
        $dialog = $this->dialogs[$dialogId] ?? null;
        if (!$dialog) {
            return false;
        }
        $raw = SipCodec::buildRequest(
            'ACK',
            $dialog['remoteTarget'],
            "{$dialog['localUri']};tag={$dialog['localTag']}",
            "{$dialog['remoteUri']};tag={$dialog['remoteTag']}",
            $dialog['callid'],
            $dialog['cseq'],          // ACK 携带与 INVITE 相同的 CSeq 序号
            $this->viaHost,
            (int)$this->cfg['port'],
            $this->transportName(),
            [],
            '',
            (string)$this->cfg['ua']
        );
        return $this->sendRaw($raw, [$dialog['ip'], $dialog['port']], $dialog['connId']);
    }

    public function sendInfo(int $dialogId, string $body, ?string $contentType = null) : bool
    {
        $dialog = $this->dialogs[$dialogId] ?? null;
        if (!$dialog) {
            return false;
        }
        return $this->sendInDialogRequest(
            $dialogId,
            'INFO',
            $body,
            $contentType ?? 'Application/MANSRTSP',
            []
        );
    }

    /**
     * 发起出站 SUBSCRIBE（平台订阅设备目录/报警/移动位置）
     *
     * @return int|false subscription_id 句柄
     */
    public function subscribe(string $toUri, string $eventType, int $expires = 3600, ?string $xmlBody = null)
    {
        $target = $this->resolveTarget($toUri);
        if (!$target) {
            return false;
        }
        [$user, $ip, $port, $connId] = $target;

        $sid = $this->nextId();
        $leg = $this->newOutLeg('SUBSCRIBE', $sid, $user, $ip, $port, $connId);
        $leg['subscriptionId'] = $sid;

        $hdrs = [
            'Event'   => $eventType,
            'Expires' => $expires,
        ];
        if ($xmlBody !== null && $xmlBody !== '') {
            $hdrs['Content-Type'] = 'Application/MANSCDP+xml';
        }
        $raw = SipCodec::buildRequest(
            'SUBSCRIBE',
            "sip:{$user}@{$ip}:{$port}",
            "<sip:{$this->cfg['sipId']}@{$this->cfg['sipRealm']}>;tag={$leg['fromTag']}",
            "<sip:{$user}@{$this->cfg['sipRealm']}>",
            $leg['callid'],
            $leg['cseq'],
            $this->viaHost,
            (int)$this->cfg['port'],
            $this->transportName(),
            $hdrs,
            $xmlBody ?? '',
            (string)$this->cfg['ua']
        );
        $leg['raw']    = $raw;
        $leg['event']  = $eventType;
        $leg['expires'] = $expires;
        $this->calls[$sid] = $leg;
        $this->indexOut($leg);

        if (!$this->sendRaw($raw, [$ip, $port], $connId)) {
            unset($this->calls[$sid]);
            return false;
        }
        $this->startRetransmit($sid, 4.0);
        return $sid;
    }

    /**
     * 续订订阅。业务侧传入的实为 dialog_id（SubscribeService 存库后回传的值）
     */
    public function refreshSubscribe(int $subscriptionId, int $expires = 3600) : bool
    {
        $dialog = $this->dialogs[$subscriptionId]
            ?? ($this->subs[$subscriptionId]['did'] ?? null ? $this->dialogs[$this->subs[$subscriptionId]['did']] : null);
        if (!$dialog) {
            return false;
        }
        $did = $dialog['did'];
        $ok  = $this->sendInDialogRequest($did, 'SUBSCRIBE', '', '', ['Expires' => $expires]);
        if (isset($this->subs[$subscriptionId])) {
            $this->subs[$subscriptionId]['expires'] = $expires;
        }
        return $ok;
    }

    public function cancelSubscribe(int $subscriptionId) : bool
    {
        $sub = $this->subs[$subscriptionId] ?? null;
        if (!$sub) {
            return false;
        }
        $did = $sub['did'];
        $ok  = $this->sendInDialogRequest($did, 'SUBSCRIBE', '', '', ['Expires' => 0]);
        unset($this->subs[$subscriptionId]);
        $this->removeDialog($did);
        return $ok;
    }

    // =====================================================================
    // 入站：响应构建（供业务通过 tid 应答）
    // =====================================================================

    public function sendResponse(int $tid, int $code, ?string $reason = null, ?array $headers = null) : bool
    {
        return $this->answerTxn($tid, $code, $reason ?? '', $headers ?? [], '', '');
    }

    public function sendCallAnswer(int $tid, int $code, ?string $body = null, ?string $reason = null, ?string $contentType = null) : bool
    {
        $contentType = $contentType ?? ($body ? 'application/sdp' : '');
        return $this->answerTxn($tid, $code, $reason ?? '', [], $body ?? '', $contentType);
    }

    public function sendNotifyResponse(int $tid, int $code) : bool
    {
        $ok = $this->answerTxn($tid, $code, $code === 200 ? 'OK' : '', [], '', '');
        // 入站 SUBSCRIBE 200 后建立对话（设备后续 NOTIFY 走该对话）
        if ($ok && $code === 200) {
            $txn = $this->inTxns[$tid] ?? null;
            // answerTxn 已删除事务，这里从 dialog 建立函数重查 —— 见 answerTxn 内部处理
        }
        return $ok;
    }

    // =====================================================================
    // 静态
    // =====================================================================

    public static function parseSdp(string $sdp) : ?array
    {
        return SipCodec::parseSdp($sdp);
    }

    // =====================================================================
    // 内部：收包与事件路由
    // =====================================================================

    /** @var int|null recvfrom 参数个数（Swoole 4.x/5.x: 3 参数含 size；6.x: 2 参数无 size） */
    private ?int $recvfromArity = null;

    private function udpRecvLoop() : void
    {
        while ($this->running) {
            try {
                $peer = [];
                if ($this->recvfromArity === null) {
                    $this->recvfromArity = (new \ReflectionMethod($this->udpSock, 'recvfrom'))->getNumberOfParameters();
                }
                $data = $this->recvfromArity >= 3
                    ? $this->udpSock->recvfrom($peer, 65535, 1.0)
                    : $this->udpSock->recvfrom($peer, 1.0);
                if ($data === false || $data === '') {
                    if ($this->isSockTimeout($this->udpSock)) {
                        continue;
                    }
                    if (!$this->running) {
                        break;
                    }
                    continue;
                }
                $this->onPacket($data, [$peer['address'], $peer['port']], null);
            } catch (\Throwable $e) {
                if ($this->running) {
                    $this->log('udp recv error: ' . $e->getMessage(), 'ERROR');
                    Coroutine::sleep(0.05);   // 参数/异常风暴保护
                }
            }
        }
    }

    private function tcpAcceptLoop() : void
    {
        while ($this->running) {
            try {
                $conn = $this->tcpListen->accept(1.0);
                if ($conn === false) {
                    continue;
                }
                $connId = $this->nextId();
                $addr   = $conn->getPeerName();
                $this->tcpConns[$connId] = ['sock' => $conn, 'addr' => [$addr['host'] ?? '0.0.0.0', (int)($addr['port'] ?? 0)], 'rbuf' => ''];
                $cid = $connId;
                Coroutine::create(function () use ($cid) {
                    $this->tcpConnLoop($cid);
                });
            } catch (\Throwable $e) {
                if ($this->running) {
                    $this->log('tcp accept error: ' . $e->getMessage(), 'ERROR');
                }
            }
        }
    }

    private function tcpConnLoop(int $connId) : void
    {
        while ($this->running && isset($this->tcpConns[$connId])) {
            $conn = $this->tcpConns[$connId];
            try {
                $data = $conn['sock']->recv(65535, 1.0);
                if ($data === false || $data === '') {
                    if ($this->isSockTimeout($conn['sock'])) {
                        continue;
                    }
                    unset($this->tcpConns[$connId]);   // 对端关闭
                    return;
                }
                $this->tcpConns[$connId]['rbuf'] .= $data;
                $this->drainTcpBuffer($connId);
            } catch (\Throwable $e) {
                unset($this->tcpConns[$connId]);
                return;
            }
        }
    }

    /** TCP 粘包处理：按 Content-Length 切分完整 SIP 报文 */
    private function drainTcpBuffer(int $connId) : void
    {
        while (true) {
            $buf  = &$this->tcpConns[$connId]['rbuf'];
            $pos  = strpos($buf, "\r\n\r\n");
            $sep  = 4;
            if ($pos === false) {
                $pos = strpos($buf, "\n\n");
                $sep = 2;
                if ($pos === false) {
                    return;
                }
            }
            $head = substr($buf, 0, $pos);
            $rest = substr($buf, $pos + $sep);

            $cl = 0;
            if (preg_match('/Content-Length\s*:\s*(\d+)/i', $head, $m)) {
                $cl = (int)$m[1];
            }
            if (strlen($rest) < $cl) {
                return;   // 等待更多数据
            }
            $body  = substr($rest, 0, $cl);
            $frame = str_replace("\n", "\r\n", $head) . "\r\n\r\n" . $body;
            $buf   = substr($rest, $cl);

            $this->onPacket($frame, $this->tcpConns[$connId]['addr'], $connId);
            if (!isset($this->tcpConns[$connId])) {
                return;
            }
        }
    }

    private function onPacket(string $raw, array $addr, ?int $connId) : void
    {
        $raw = SipCodec::decodeCharset($raw);
        $msg = SipCodec::parse($raw);
        if ($msg['method'] === null && $msg['status_code'] === null) {
            $this->log('unparsable SIP packet from ' . $addr[0] . ':' . $addr[1], 'DEBUG');
            return;
        }
        $this->log('[RX] ' . ($msg['is_request'] ? $msg['method'] : 'SIP/2.0 ' . $msg['status_code'])
            . ' from ' . $addr[0] . ':' . $addr[1], 'DEBUG');

        if ($msg['is_request']) {
            // eXosip 行为：顶层 Via 注入 received/rport（GB28181Handler 依赖）
            SipCodec::injectViaReceived($msg, (string)$addr[0], (int)$addr[1]);
            $this->routeRequest($msg, $addr, $connId);
        } else {
            $this->routeResponse($msg, $addr, $connId);
        }
    }

    private function routeRequest(array $msg, array $addr, ?int $connId) : void
    {
        $method = $msg['method'];
        $tid    = $this->nextId();
        $callid = $msg['header_map']['call-id'] ?? '';

        $txn = [
            'msg'    => $msg,
            'addr'   => $addr,
            'connId' => $connId,
            'cid'    => null,
            'method' => $method,
        ];

        switch ($method) {
            case 'REGISTER':
                // 记录/清除设备注册地址（sendMessage 目标解析用）
                [$user] = array_pad(SipCodec::parseUri((string)($msg['header_map']['from'] ?? '')), 3, '');
                $expires = (int)($msg['header_map']['expires'] ?? 0);
                if ($user !== '') {
                    if ($expires === 0) {
                        unset($this->regs[$user]);
                    } else {
                        $this->regs[$user] = ['ip' => $addr[0], 'port' => $addr[1], 'connId' => $connId];
                    }
                }
                $this->inTxns[$tid] = $txn;
                $this->dispatchEvent('onRegister', $this->buildEvent($msg, $addr, $connId, ['tid' => $tid, 'expires' => $expires]));
                return;

            case 'MESSAGE':
                $this->inTxns[$tid] = $txn;
                $this->dispatchEvent('onMessage', $this->buildEvent($msg, $addr, $connId, ['tid' => $tid]));
                return;

            case 'INVITE':
                $cid = $this->nextId();
                $txn['cid'] = $cid;
                $this->inTxns[$tid] = $txn;
                $this->calls[$cid] = [
                    'dir'     => 'in',
                    'cid'     => $cid,
                    'callid'  => $callid,
                    'method'  => 'INVITE',
                    'fromUri' => $msg['header_map']['from'] ?? '',
                    'toUri'   => $msg['header_map']['to'] ?? '',
                    'fromTag' => $this->tagOf($msg['header_map']['from'] ?? ''),
                    'toTag'   => $this->tagOf($msg['header_map']['to'] ?? ''),
                    'addr'    => $addr,
                    'connId'  => $connId,
                    'cseq'    => $this->cseqOf($msg['header_map']['cseq'] ?? ''),
                    'created' => microtime(true),
                ];
                $this->dispatchEvent('onInvite', $this->buildEvent($msg, $addr, $connId, ['tid' => $tid, 'callId' => $cid]));
                return;

            case 'ACK':
                $did = $this->findDialogByTags($callid, $msg['header_map']['from'] ?? '', $msg['header_map']['to'] ?? '');
                if ($did !== null) {
                    $d = $this->dialogs[$did];
                    $this->dispatchEvent('onAck', $this->buildEvent($msg, $addr, $connId, [
                        'callId'   => $d['cid'],
                        'dialogId' => $did,
                    ]));
                } else {
                    $this->log('ACK without dialog: callid=' . $callid, 'DEBUG');
                }
                return;

            case 'BYE':
                $this->inTxns[$tid] = $txn;
                $did = $this->findDialogByTags($callid, $msg['header_map']['from'] ?? '', $msg['header_map']['to'] ?? '');
                // eXosip automatic_action：BYE 的 200 OK 自动回复
                $this->answerTxn($tid, 200, 'OK', [], '', '');
                $evData = ['tid' => $tid];
                if ($did !== null) {
                    $d = $this->dialogs[$did];
                    $evData['callId']   = $d['cid'];
                    $evData['dialogId'] = $did;
                    $this->removeDialog($did);
                }
                $this->dispatchEvent('onBye', $this->buildEvent($msg, $addr, $connId, $evData));
                return;

            case 'SUBSCRIBE':
                $this->inTxns[$tid] = $txn;
                $this->dispatchEvent('onSubscribe', $this->buildEvent($msg, $addr, $connId, [
                    'tid'     => $tid,
                    'expires' => (int)($msg['header_map']['expires'] ?? 0),
                ]));
                return;

            case 'NOTIFY':
                $this->inTxns[$tid] = $txn;
                // eXosip automatic_action：NOTIFY 的 200 OK 自动回复
                $this->answerTxn($tid, 200, 'OK', [], '', '');
                $did = $this->findDialogByTags($callid, $msg['header_map']['from'] ?? '', $msg['header_map']['to'] ?? '');
                $evData = ['tid' => $tid];
                if ($did !== null) {
                    $d = $this->dialogs[$did];
                    $evData['callId']   = $d['cid'];
                    $evData['dialogId'] = $did;
                    foreach ($this->subs as $sid => $s) {
                        if ($s['did'] === $did) {
                            $evData['subscriptionId'] = $sid;
                            break;
                        }
                    }
                }
                $this->dispatchEvent('onNotify', $this->buildEvent($msg, $addr, $connId, $evData));
                return;

            case 'INFO':
                $this->inTxns[$tid] = $txn;
                $this->dispatchEvent('onInfo', $this->buildEvent($msg, $addr, $connId, ['tid' => $tid]));
                return;

            case 'UPDATE':
                $this->inTxns[$tid] = $txn;
                $this->dispatchEvent('onUpdate', $this->buildEvent($msg, $addr, $connId, ['tid' => $tid]));
                return;

            case 'REFER':
                $this->inTxns[$tid] = $txn;
                $this->dispatchEvent('onRefer', $this->buildEvent($msg, $addr, $connId, ['tid' => $tid]));
                return;

            default:
                // OPTIONS/PING/CANCEL 等：eXosip automatic_action 自动应答
                $this->inTxns[$tid] = $txn;
                $this->answerTxn($tid, 200, 'OK', [], '', '');
                $prop = 'on' . ucfirst(strtolower($method));
                if (property_exists($this, $prop) && $this->{$prop}) {
                    $this->dispatchEvent($prop, $this->buildEvent($msg, $addr, $connId, ['tid' => $tid]));
                }
        }
    }

    private function routeResponse(array $msg, array $addr, ?int $connId) : void
    {
        $code   = (int)$msg['status_code'];
        $callid = $msg['header_map']['call-id'] ?? '';
        $cseq   = $msg['header_map']['cseq'] ?? '';
        [$cseqNum, $cseqMethod] = array_pad(preg_split('/\s+/', trim($cseq), 2), 2, '');

        // INVITE 2xx 重传：直接按对话命中
        if ($code >= 200 && $code < 300 && strtoupper((string)$cseqMethod) === 'INVITE') {
            $did = $this->findDialogByTags($callid, $msg['header_map']['from'] ?? '', $msg['header_map']['to'] ?? '', false);
            if ($did !== null) {
                $this->fireResponseEvent(EXOSIP_CALL_ANSWERED, $code, $msg, $addr, $connId, $did);
                return;
            }
        }

        $cid = $this->findOutCall($callid, strtoupper((string)$cseqMethod));
        if ($cid === null) {
            $this->log("response unmatched: code={$code} callid={$callid} cseq={$cseq}", 'DEBUG');
            return;
        }
        $leg = $this->calls[$cid];

        // UAC Digest 重试：401/407 挑战且尚未带过 Authorization
        if (($code === 401 || $code === 407)
            && empty($leg['authed'])
            && ($challenge = $msg['header_map']['www-authenticate'] ?? $msg['header_map']['proxy-authenticate'] ?? null)
        ) {
            $retry = $this->buildAuthRetry($leg, (string)$challenge, strtoupper((string)$cseqMethod));
            if ($retry !== null) {
                $leg['raw']    = $retry;
                $leg['authed'] = true;
                $this->calls[$cid] = $leg;
                $this->sendRaw($retry, [$leg['ip'], $leg['port']], $leg['connId']);
                return;
            }
        }

        $method = strtoupper((string)$cseqMethod);
        if ($code >= 200 && $code < 300) {
            $leg['answered'] = true;
            $this->stopRetransmit($cid);
            $this->unindexOut($leg);

            $did    = 0;
            $sidOut = 0;
            if ($method === 'INVITE' || $method === 'SUBSCRIBE') {
                $did = $this->ensureDialogFromResponse($cid, $leg, $msg, $addr, $connId);
                if ($method === 'SUBSCRIBE' && !empty($leg['subscriptionId'])) {
                    $sidOut = $leg['subscriptionId'];
                    $this->subs[$sidOut] = [
                        'did'       => $did,
                        'event'     => $leg['event'] ?? '',
                        'expires'   => (int)($leg['expires'] ?? 3600),
                        'device_id' => $leg['user'] ?? '',
                    ];
                }
            }
            $type = match ($method) {
                'INVITE'    => EXOSIP_CALL_ANSWERED,
                'SUBSCRIBE' => EXOSIP_SUBSCRIPTION_ANSWERED,
                'INFO'      => EXOSIP_CALL_MESSAGE_ANSWERED,
                default     => EXOSIP_MESSAGE_ANSWERED,
            };
            $this->fireResponseEvent($type, $code, $msg, $addr, $connId, $did, $sidOut, $cid);

            if ($method !== 'INVITE') {
                unset($this->calls[$cid]);   // INVITE 腿保留（BYE 按对话走，保留便于排查）
            }
            return;
        }

        if ($code >= 100 && $code < 200) {
            $type = match ($method) {
                'INVITE'    => $code === 180 ? EXOSIP_CALL_RINGING : EXOSIP_CALL_PROCEEDING,
                'SUBSCRIBE' => EXOSIP_SUBSCRIPTION_PROCEEDING,
                default     => EXOSIP_MESSAGE_PROCEEDING,
            };
            $this->fireResponseEvent($type, $code, $msg, $addr, $connId, 0, 0, $cid);
            return;
        }

        // 3xx-6xx
        $class = $code >= 600 ? 'GLOBALFAILURE' : ($code >= 500 ? 'SERVERFAILURE' : ($code >= 400 ? 'REQUESTFAILURE' : 'REDIRECTED'));
        $family = match ($method) {
            'INVITE'    => 'EXOSIP_CALL_',
            'SUBSCRIBE' => 'EXOSIP_SUBSCRIPTION_',
            'INFO'      => 'EXOSIP_CALL_MESSAGE_',
            default     => 'EXOSIP_MESSAGE_',
        };
        $type = constant($family . $class);
        $this->stopRetransmit($cid);
        $this->unindexOut($leg);
        unset($this->calls[$cid]);

        $sidOut = $leg['subscriptionId'] ?? 0;
        $this->fireResponseEvent($type, $code, $msg, $addr, $connId, 0, (int)$sidOut, $cid);
    }

    private function fireResponseEvent(int $type, int $code, array $msg, array $addr, ?int $connId, int $did = 0, int $sid = 0, int $cid = 0) : void
    {
        $dialog = $did > 0 ? ($this->dialogs[$did] ?? null) : null;
        $this->dispatchEvent('onResponse', $this->buildEvent($msg, $addr, $connId, [
            'type'           => $type,
            'code'           => $code,
            'callId'         => $dialog['cid'] ?? $cid,
            'dialogId'       => $did,
            'subscriptionId' => $sid,
        ]));
    }

    // =====================================================================
    // 内部：事务应答与对话
    // =====================================================================

    private function answerTxn(int $tid, int $code, string $reason, array $headers, string $body, string $contentType) : bool
    {
        $txn = $this->inTxns[$tid] ?? null;
        if (!$txn) {
            $this->log("answerTxn: txn {$tid} not found", 'DEBUG');
            return false;
        }
        $msg   = $txn['msg'];
        $toTag = $this->tagOf($msg['header_map']['to'] ?? '');
        if ($toTag === '' && $code >= 200) {
            $toTag = (string)random_int(10000000, 99999999);
        }
        $reasonMap = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 500 => 'Server Internal Error'];
        if ($reason === '') {
            $reason = $reasonMap[$code] ?? 'Unknown';
        }
        $raw = SipCodec::buildResponse($msg, $code, $reason, $toTag, $headers, $body, $contentType, (string)$this->cfg['ua']);
        $ok  = $this->sendRaw($raw, $txn['addr'], $txn['connId']);

        if ($code >= 200) {
            $method = $txn['method'];
            $cid    = $txn['cid'];
            unset($this->inTxns[$tid]);
            // 入站 INVITE 200：建立对话（设备随后的 ACK/BYE 落到该对话）
            if ($method === 'INVITE' && $cid !== null && isset($this->calls[$cid])) {
                $this->establishInDialog($cid, $toTag);
            }
            // 入站 SUBSCRIBE 200：建立对话（设备 NOTIFY 走该对话）
            if ($method === 'SUBSCRIBE') {
                $this->establishInDialogForSubscribe($txn, $toTag);
            }
        }
        return $ok;
    }

    private function establishInDialog(int $cid, string $localTag) : void
    {
        $leg = $this->calls[$cid] ?? null;
        if (!$leg) {
            return;
        }
        $did = $this->nextId();
        $this->dialogs[$did] = [
            'did'         => $did,
            'cid'         => $cid,
            'callid'      => $leg['callid'],
            'localUri'    => $leg['toUri'],
            'remoteUri'   => $leg['fromUri'],
            'localTag'    => $localTag !== '' ? $localTag : ('L' . $cid),
            'remoteTag'   => $leg['fromTag'],
            'remoteTarget' => $this->contactUri($leg['connId'], $leg['addr'], $leg['fromUri']),
            'ip'          => $leg['addr'][0],
            'port'        => $leg['addr'][1],
            'connId'      => $leg['connId'],
            'cseq'        => $leg['cseq'] ?: 1,
            'created'     => microtime(true),
        ];
        $this->indexDialog($leg['callid'], $did);
    }

    private function establishInDialogForSubscribe(array $txn, string $localTag) : void
    {
        $msg    = $txn['msg'];
        $callid = $msg['header_map']['call-id'] ?? '';
        $from   = $msg['header_map']['from'] ?? '';
        $to     = $msg['header_map']['to'] ?? '';
        if ($this->findDialogByTags($callid, $from, $to) !== null) {
            return;   // 已存在（设备刷新订阅）
        }
        $did = $this->nextId();
        $this->dialogs[$did] = [
            'did'          => $did,
            'cid'          => 0,
            'callid'       => $callid,
            'localUri'     => $to,
            'remoteUri'    => $from,
            'localTag'     => $localTag !== '' ? $localTag : ('L' . $did),
            'remoteTag'    => $this->tagOf($from),
            'remoteTarget' => $this->contactUri($txn['connId'], $txn['addr'], $from),
            'ip'           => $txn['addr'][0],
            'port'         => $txn['addr'][1],
            'connId'       => $txn['connId'],
            'cseq'         => 1,
            'created'      => microtime(true),
        ];
        $this->indexDialog($callid, $did);
    }

    /** 出站 INVITE/SUBSCRIBE 收到 2xx：建立（或复用）对话 */
    private function ensureDialogFromResponse(int $cid, array $leg, array $msg, array $addr, ?int $connId) : int
    {
        $callid  = $leg['callid'];
        $fromTag = $leg['fromTag'];
        // INVITE 200 OK 重传：同 callid 且本地 tag 一致 → 复用已有对话
        foreach ($this->dialogIndex[$callid] ?? [] as $did) {
            if (($this->dialogs[$did]['localTag'] ?? '') === $fromTag) {
                return $did;
            }
        }
        $did = $this->nextId();
        $this->dialogs[$did] = [
            'did'          => $did,
            'cid'          => $cid,
            'callid'       => $callid,
            'localUri'     => "<sip:{$this->cfg['sipId']}@{$this->cfg['sipRealm']}>",
            'remoteUri'    => $msg['header_map']['to'] ?? "<sip:{$leg['user']}@{$this->cfg['sipRealm']}>",
            'localTag'     => $fromTag,     // 出站方向：本地 = From
            'remoteTag'    => $this->tagOf($msg['header_map']['to'] ?? ''),
            'remoteTarget' => "sip:{$leg['ip']}:{$leg['port']}",
            'ip'           => $leg['ip'],
            'port'         => $leg['port'],
            'connId'       => $leg['connId'],
            'cseq'         => $leg['cseq'],
            'created'      => microtime(true),
        ];
        $this->indexDialog($callid, $did);
        return $did;
    }

    private function indexDialog(string $callid, int $did) : void
    {
        $this->dialogIndex[$callid][] = $did;
    }

    private function removeDialog(int $did) : void
    {
        $d = $this->dialogs[$did] ?? null;
        if (!$d) {
            return;
        }
        unset($this->dialogs[$did]);
        $list = $this->dialogIndex[$d['callid']] ?? [];
        $list = array_values(array_diff($list, [$did]));
        if ($list) {
            $this->dialogIndex[$d['callid']] = $list;
        } else {
            unset($this->dialogIndex[$d['callid']]);
        }
        if (!empty($d['cid'])) {
            unset($this->calls[$d['cid']]);
        }
        foreach ($this->subs as $sid => $s) {
            if ($s['did'] === $did) {
                unset($this->subs[$sid]);
            }
        }
    }

    /**
     * 按 Call-ID + tag 无序对定位对话
     * 入站对话内请求：From=远端 tag，To=本地 tag（与出站方向相反，故无序匹配）
     */
    private function findDialogByTags(string $callid, string $fromHeader, string $toHeader, bool $strict = true) : ?int
    {
        $fromTag = $this->tagOf($fromHeader);
        $toTag   = $this->tagOf($toHeader);
        foreach ($this->dialogIndex[$callid] ?? [] as $did) {
            $d = $this->dialogs[$did] ?? null;
            if (!$d) {
                continue;
            }
            if (($d['remoteTag'] === $fromTag && $d['localTag'] === $toTag)
                || ($d['remoteTag'] === $toTag && $d['localTag'] === $fromTag)
            ) {
                return $did;
            }
        }
        if (!$strict && !empty($this->dialogIndex[$callid])) {
            // 兜底：tag 缺失/变形时按 callid 唯一对话命中
            $ids = $this->dialogIndex[$callid];
            return count($ids) === 1 ? reset($ids) : null;
        }
        return null;
    }

    // =====================================================================
    // 内部：出站请求构建辅助
    // =====================================================================

    private function newOutLeg(string $method, int $cid, string $user, string $ip, int $port, ?int $connId) : array
    {
        return [
            'dir'     => 'out',
            'cid'     => $cid,
            'callid'  => SipCodec::callId($this->viaHost),
            'method'  => $method,
            'user'    => $user,
            'ip'      => $ip,
            'port'    => $port,
            'connId'  => $connId,
            'fromTag' => SipCodec::tag(),
            'toTag'   => '',
            'cseq'    => $this->cseqSeq++,
            'raw'     => '',
            'addr'    => [$ip, $port],
            'answered' => false,
            'authed'  => false,
            'created' => microtime(true),
        ];
    }

    private function sendInDialogRequest(int $did, string $method, string $body, string $contentType, array $headers) : bool
    {
        $d = $this->dialogs[$did] ?? null;
        if (!$d) {
            return false;
        }
        $d['cseq']++;
        $this->dialogs[$did] = $d;
        if ($contentType !== '') {
            $headers['Content-Type'] = $contentType;
        }
        $raw = SipCodec::buildRequest(
            $method,
            $d['remoteTarget'],
            "{$d['localUri']};tag={$d['localTag']}",
            rtrim($d['remoteUri'], '>') . ">;tag={$d['remoteTag']}",
            $d['callid'],
            $d['cseq'],
            $this->viaHost,
            (int)$this->cfg['port'],
            $this->transportName(),
            $headers,
            $body,
            (string)$this->cfg['ua']
        );
        return $this->sendRaw($raw, [$d['ip'], $d['port']], $d['connId']);
    }

    /** 401/407 Digest 重试（UAC） */
    private function buildAuthRetry(array $leg, string $challenge, string $method) : ?string
    {
        $p = SipCodec::parseDigestParams($challenge);
        $realm  = $p['realm'] ?? $this->cfg['sipRealm'];
        $nonce  = $p['nonce'] ?? '';
        if ($nonce === '') {
            return null;
        }
        $username = $this->cfg['username'] ?: $this->cfg['sipId'];
        $password = (string)$this->cfg['password'];
        $uri      = "sip:{$leg['user']}@{$leg['ip']}:{$leg['port']}";
        $ha1      = md5("{$username}:{$realm}:{$password}");
        $ha2      = md5("{$method}:{$uri}");
        $response = md5("{$ha1}:{$nonce}:{$ha2}");
        $auth     = "Digest username=\"{$username}\", realm=\"{$realm}\", nonce=\"{$nonce}\", uri=\"{$uri}\", response=\"{$response}\", algorithm=MD5";
        return SipCodec::appendHeader($leg['raw'], 'Authorization', $auth);
    }

    /**
     * 出站 URI 解析：sip:user@ip:port 直发；sip:user@realm 查注册表
     * @return array|null [user, ip, port, connId]
     */
    private function resolveTarget(string $to) : ?array
    {
        [$user, $host, $port] = SipCodec::parseUri($to);
        $connId = null;
        if (!SipCodec::isIp($host)) {
            $reg = $this->regs[$user] ?? null;
            if (!$reg) {
                $this->log("resolveTarget failed: {$to} (device not registered)", 'DEBUG');
                return null;
            }
            [$host, $port, $connId] = [$reg['ip'], $reg['port'], $reg['connId']];
        }
        if ($user === '') {
            $user = $this->cfg['sipId'];
        }
        return [$user, $host, (int)$port, $connId];
    }

    // =====================================================================
    // 内部：重传 / 索引 / 工具
    // =====================================================================

    /** @var array<int, bool> 重传取消标记 */
    private array $retransStop = [];

    private function startRetransmit(int $cid, float $maxWait) : void
    {
        $leg = $this->calls[$cid] ?? null;
        if (!$leg || $this->transportName() !== 'UDP') {
            return;   // TCP 由传输层保证可靠性，无需应用层重传
        }
        unset($this->retransStop[$cid]);
        Coroutine::create(function () use ($cid, $maxWait) {
            $interval = 0.5;
            $elapsed  = 0.0;
            while ($elapsed < $maxWait) {
                Coroutine::sleep($interval);
                if ($this->retransStop[$cid] ?? false) {
                    unset($this->retransStop[$cid]);
                    return;
                }
                if (!isset($this->calls[$cid])) {
                    return;
                }
                $leg = $this->calls[$cid];
                if (!empty($leg['answered'])) {
                    return;
                }
                $this->sendRaw($leg['raw'], [$leg['ip'], $leg['port']], null);
                $elapsed  += $interval;
                $interval *= 2;
            }
            if (isset($this->calls[$cid])) {
                // Timer B/F 超时：合成 408 事件（eXosip 行为）
                $leg = $this->calls[$cid];
                unset($this->calls[$cid]);
                $this->unindexOut($leg);
                $family = $leg['method'] === 'INVITE' ? 'EXOSIP_CALL_' : ($leg['method'] === 'SUBSCRIBE' ? 'EXOSIP_SUBSCRIPTION_' : 'EXOSIP_MESSAGE_');
                $sidOut = (int)($leg['subscriptionId'] ?? 0);
                $type   = constant($family . 'REQUESTFAILURE');
                $this->dispatch(function () use ($type, $cid, $sidOut) {
                    $ev = new SipEvent([
                        'type'           => $type,
                        'code'           => 408,
                        'callId'         => $cid,
                        'dialogId'       => 0,
                        'subscriptionId' => $sidOut,
                    ]);
                    $this->fireSync($this->onResponse, $ev);
                });
            }
        });
    }

    private function stopRetransmit(int $cid) : void
    {
        $this->retransStop[$cid] = true;
    }

    private function indexOut(array $leg) : void
    {
        $this->outIndex[$leg['callid'] . '|' . $leg['method']][] = $leg['cid'];
    }

    private function unindexOut(array $leg) : void
    {
        $k = $leg['callid'] . '|' . $leg['method'];
        if (isset($this->outIndex[$k])) {
            $this->outIndex[$k] = array_values(array_diff($this->outIndex[$k], [$leg['cid']]));
            if (!$this->outIndex[$k]) {
                unset($this->outIndex[$k]);
            }
        }
    }

    private function findOutCall(string $callid, string $method) : ?int
    {
        $list = $this->outIndex[$callid . '|' . $method] ?? [];
        return $list ? end($list) : null;
    }

    private function sendRaw(string $data, array $addr, ?int $connId) : bool
    {
        if ($connId !== null && isset($this->tcpConns[$connId])) {
            try {
                $this->tcpConns[$connId]['sock']->sendAll($data, 3.0);
                return true;
            } catch (\Throwable $e) {
                unset($this->tcpConns[$connId]);
                $this->log('tcp send failed: ' . $e->getMessage(), 'ERROR');
                return false;
            }
        }
        if ($this->transportName() === 'TCP') {
            try {
                $sock = $this->getTcpOutClient((string)$addr[0], (int)$addr[1]);
                $sock->sendAll($data, 3.0);
                return true;
            } catch (\Throwable $e) {
                $this->log('tcp out send failed: ' . $e->getMessage(), 'ERROR');
                return false;
            }
        }
        try {
            $this->udpSock?->sendto((string)$addr[0], (int)$addr[1], $data);
            return true;
        } catch (\Throwable $e) {
            $this->log('udp send failed: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /** 协程 socket 读超时判定（Linux errno 110 / macOS 35 / SOCKET_ETIMEDOUT 常量可用时一并比较） */
    private function isSockTimeout(CoSocket $sock) : bool
    {
        $code = $sock->errCode;
        return $code === 110 || $code === 35
            || (defined('SOCKET_ETIMEDOUT') && $code === SOCKET_ETIMEDOUT)
            || (defined('SWOOLE_ETIMEOUT') && $code === SWOOLE_ETIMEOUT);
    }

    private function getTcpOutClient(string $ip, int $port) : CoSocket
    {
        $key = "{$ip}:{$port}";
        if (isset($this->tcpOut[$key])) {
            return $this->tcpOut[$key];
        }
        $sock = new CoSocket(AF_INET, SOCK_STREAM);
        if (!$sock->connect($ip, $port, 3.0)) {
            throw new \RuntimeException("connect {$ip}:{$port} failed: {$sock->errMsg}");
        }
        $this->tcpOut[$key] = $sock;
        return $sock;
    }

    private function transportName() : string
    {
        return $this->cfg['mode'] === 'TCP' ? 'TCP' : 'UDP';
    }

    private function contactUri(?int $connId, array $addr, string $fallbackUri) : string
    {
        $c = $connId !== null ? ($this->tcpConns[$connId]['sock'] ?? null) : null;
        if ($c) {
            $peer = $c->getPeerName();
            if (!empty($peer['host'])) {
                return "sip:{$peer['host']}:" . (int)($peer['port'] ?? 5060);
            }
        }
        if (!empty($addr[0])) {
            return 'sip:' . $addr[0] . ':' . (int)$addr[1];
        }
        [$user, $host, $port] = SipCodec::parseUri($fallbackUri);
        return "sip:{$host}:{$port}";
    }

    private function buildEvent(array $msg, array $addr, ?int $connId, array $extra = []) : SipEvent
    {
        return new SipEvent(array_merge([
            'method'      => $msg['method'],
            'code'        => $msg['status_code'],
            'fromUri'     => $msg['header_map']['from'] ?? null,
            'toUri'       => $msg['header_map']['to'] ?? null,
            'requestUri'  => $msg['uri'] ?? null,
            'body'        => $msg['body'] !== '' ? $msg['body'] : null,
            'contentType' => $msg['header_map']['content-type'] ?? null,
            'headers'     => $msg['headers'],
            'headerMap'   => $msg['header_map'],
            'expires'     => (int)($msg['header_map']['expires'] ?? 0),
            'connection'  => ['ip' => $addr[0], 'port' => $addr[1]],
            'transport'   => $connId !== null ? 'TCP' : 'UDP',
        ], $extra));
    }

    private function dispatchEvent(string $prop, SipEvent $event) : void
    {
        $this->dispatch(function () use ($prop, $event) {
            $this->fireSync($this->{$prop}, $event);
        });
    }

    private function dispatch(\Closure $fn) : void
    {
        $this->dispatchQ?->push($fn);
    }

    private function fireSync($cb, ...$args)
    {
        if ($cb === null) {
            return null;
        }
        return $cb(...$args);
    }

    private function nextId() : int
    {
        return ++$this->idSeq;
    }

    private function tagOf(string $header) : string
    {
        return preg_match('/tag=([^;>\s]+)/i', $header, $m) ? $m[1] : '';
    }

    private function cseqOf(string $header) : int
    {
        return (int)preg_replace('/\D.*$/s', '', trim($header));
    }

    private function detectLocalIp() : string
    {
        if (!empty($this->cfg['public_ip'])) {
            return (string)$this->cfg['public_ip'];
        }
        if ($this->cfg['ip'] !== '0.0.0.0' && $this->cfg['ip'] !== '') {
            return (string)$this->cfg['ip'];
        }
        $ip = gethostbyname(gethostname() ?: 'localhost');
        return $ip !== false && SipCodec::isIp($ip) ? $ip : '127.0.0.1';
    }

    private function log(string $msg, string $level = 'INFO') : void
    {
        if ($level === 'DEBUG' && !$this->cfg['debug']) {
            return;
        }
        if ($this->logger !== null) {
            $method = strtolower($level === 'WARNING' ? 'error' : $level);
            if (method_exists($this->logger, $method)) {
                $this->logger->{$method}("[ExoSip] {$msg}");
                return;
            }
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] [ExoSip] [{$level}] {$msg}\n");
        }
    }
}
