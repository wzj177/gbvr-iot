<?php

declare(strict_types=1);

/**
 * Swoole ExoSip 引擎端到端冒烟测试（需 ext-swoole，本机 6.0 即可）
 *
 * 模拟一台 GB28181 假设备，与引擎在 UDP 回环上完成完整信令流：
 *   REGISTER 401 挑战 → Digest 认证 → 200
 *   MESSAGE(Keepalive) → 200
 *   服务端 sendMessage（realm URI → 注册表解析）→ 设备收到
 *   服务端 sendInvite → 设备回 200 OK(SDP y=) → CALL_ANSWERED(dialog+ssrc) → ACK → 设备 BYE → 自动 200 + onBye
 *   SUBSCRIBE → 200 → SUBSCRIPTION_ANSWERED(sid) → refreshSubscribe → cancelSubscribe
 *   设备入站 SUBSCRIBE → sendNotifyResponse 200
 *   addTask/onTaskFinish、sendToWorker/onPipeMessage 管道
 *   INVITE 200 OK 重传去重（同 dialog_id）
 *
 * 用法：php swoole-gbs/tests/engine_smoke_test.php
 */

require_once __DIR__ . '/../../Gb28181Gateway/src/Swoole/constants.php';
require_once __DIR__ . '/../../Gb28181Gateway/src/Swoole/SipCodec.php';
require_once __DIR__ . '/../../Gb28181Gateway/src/Swoole/SipEvent.php';
require_once __DIR__ . '/../../Gb28181Gateway/src/Swoole/ExoSip.php';

use Gb28181\GateWay\Swoole\ExoSip as SwooleExoSip;
use Gb28181\GateWay\Swoole\SipCodec;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket as CoSocket;

// 直接使用命名空间版引擎（本机可能同时加载 php-exosip C 扩展，全局 \ExoSip 归其所有，互不干扰）

const SERVER_PORT = 25160;   // 随机偏移避撞；被占用时可在下方再调
const DEV_ID   = '34020000001320000001';
const SIP_ID   = '34020000002000000001';
const REALM    = '3402000000';
const PASSWORD = '12345678';

$pass = 0;
$fail = 0;
function check(string $name, bool $cond) : void
{
    global $pass, $fail;
    echo ($cond ? '  ✔ ' : '  ✘ ') . $name . "\n";
    $cond ? $pass++ : $fail++;
}

\Gb28181\GateWay\Swoole\ExoSip::coroutineRun(function () {
    // ---------- 假设备 socket ----------
    $dev = new CoSocket(AF_INET, SOCK_DGRAM);
    $dev->bind('127.0.0.1', 0);
    $sn = $dev->getsockname();
    $devPort = (int)$sn['port'];
    echo "== 假设备就绪: 127.0.0.1:{$devPort} ==\n";

    $isTimeout = function (CoSocket $sock) : bool {
        $code = $sock->errCode;
        return $code === 110 || $code === 35 || (defined('SOCKET_ETIMEDOUT') && $code === SOCKET_ETIMEDOUT);
    };

    // recvfrom 跨版本签名：Swoole 4.x/5.x 为 ($peer, $size, $timeout)，6.x 为 ($peer, $timeout)
    $recvfromArity = (new ReflectionMethod($dev, 'recvfrom'))->getNumberOfParameters();
    $recvSip = function (float $timeout = 3.0) use ($dev, $isTimeout, $recvfromArity) : ?array {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $peer = [];
            $data = $recvfromArity >= 3
                ? $dev->recvfrom($peer, 65535, 0.5)
                : $dev->recvfrom($peer, 0.5);
            if ($data !== false && $data !== '') {
                return SipCodec::parse(SipCodec::decodeCharset($data));
            }
            if ($data === false && !$isTimeout($dev)) {
                return null;
            }
        }
        return null;
    };

    // 设备侧构建响应：复制 Via/From/To/Call-ID/CSeq，To 补 tag
    $devResponse = function (array $req, int $code, string $reason, array $extra = [], string $body = '') : string {
        $lines = ["SIP/2.0 {$code} {$reason}"];
        foreach ($req['headers'] as $h) {
            if (strcasecmp($h['name'], 'Via') === 0) {
                $lines[] = "Via: {$h['value']}";
            }
        }
        $from = $req['header_map']['from'] ?? '';
        $to   = $req['header_map']['to'] ?? '';
        if (stripos($to, 'tag=') === false) {
            $to = rtrim($to, '>') . '>;tag=devtag' . random_int(1000, 9999);
        }
        $lines[] = "From: {$from}";
        $lines[] = "To: {$to}";
        $lines[] = 'Call-ID: ' . ($req['header_map']['call-id'] ?? '');
        $lines[] = 'CSeq: ' . ($req['header_map']['cseq'] ?? '');
        foreach ($extra as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $lines[] = 'Content-Length: ' . strlen($body);
        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    };

    // ---------- 引擎 + 模拟 GB28181Handler 行为 ----------
    $state = [];
    $sip = new SwooleExoSip([
        'ip' => '127.0.0.1', 'port' => SERVER_PORT, 'mode' => 'UDP',
        'sipId' => SIP_ID, 'sipRealm' => REALM, 'password' => PASSWORD,
        'timer_interval' => 3600, 'task_worker_num' => 2, 'debug' => false,
        'ua' => 'SwooleExoSip-Test',
    ]);

    $sip->onRegister = function ($ev) use ($sip, &$state) {
        $state['via_header'] = $ev->getHeader('Via');
        $auth = $ev->getHeader('Authorization');
        if (!$auth) {
            $sip->sendResponse($ev->getTid(), 401, 'Unauthorized', [
                'WWW-Authenticate' => 'Digest realm="' . REALM . '", nonce="testnonce123", algorithm=MD5',
            ]);
            return;
        }
        $p = SipCodec::parseDigestParams($auth);
        $uri = $p['uri'] ?? $ev->getRequestUri();
        $ha1 = md5(DEV_ID . ':' . REALM . ':' . PASSWORD);
        $ha2 = md5('REGISTER:' . $uri);
        $ok = md5($ha1 . ':' . ($p['nonce'] ?? '') . ':' . $ha2) === ($p['response'] ?? '');
        if (!$ok) {
            $sip->sendResponse($ev->getTid(), 403, 'Forbidden');
            return;
        }
        $state['register_ok'] = true;
        $state['register_conn'] = $ev->getConnection();
        $sip->sendResponse($ev->getTid(), 200, 'OK', ['Expires' => 3600]);
    };

    $sip->onMessage = function ($ev) use ($sip, &$state) {
        $state['message_body'] = $ev->getBody();
        $sip->sendResponse($ev->getTid(), 200, 'OK');
    };

    $sip->onBye = function ($ev) use (&$state) {
        $state['bye_call_id'] = $ev->getCallId();
        $state['bye_dialog_id'] = $ev->getDialogId();
    };

    $sip->onSubscribe = function ($ev) use ($sip, &$state) {
        $state['inbound_subscribe'] = $ev->getExpires();
        $sip->sendNotifyResponse($ev->getTid(), 200);
    };

    $sip->onResponse = function ($ev) use ($sip, &$state) {
        $state['last_response_type'] = $ev->getType();
        $state['last_response_code'] = $ev->getCode();
        if ($ev->getType() === EXOSIP_CALL_ANSWERED && $ev->getCode() === 200) {
            $did = $ev->getDialogId();
            $sdp = $ev->getSdp();
            $state['invite_events'][] = [
                'callId' => $ev->getCallId(), 'dialogId' => $did,
                'ssrc' => $sdp['gb28181']['ssrc'] ?? null,
                'conn' => $sdp['connection']['addr'] ?? null,
            ];
            $sip->sendAck($did);
        }
        if ($ev->getType() === EXOSIP_SUBSCRIPTION_ANSWERED) {
            $state['sub_answer_sid'] = $ev->getSubscriptionId();
            $state['sub_answer_did'] = $ev->getDialogId();
        }
    };

    $sip->onTask = function ($taskId, $data) {
        return ['doubled' => ($data['x'] ?? 0) * 2];
    };
    $sip->onTaskFinish = function ($taskId, $result) use (&$state) {
        $state['task_result'] = $result;
    };
    $sip->onPipeMessage = function ($data) use (&$state) {
        $state['pipe_message'] = $data;
    };
    $sip->onTimer = function () use (&$state) {
        $state['tick_count'] = ($state['tick_count'] ?? 0) + 1;
    };

    $sip->run();
    Coroutine::sleep(0.1);

    // ========== 1. REGISTER 首次（无认证）→ 401 ==========
    echo "== REGISTER 401 挑战 ==\n";
    $reg = "REGISTER sip:" . SIP_ID . "@" . REALM . " SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:{$devPort};rport;branch=z9hG4bKdev001\r\n"
        . "From: <sip:" . DEV_ID . "@" . REALM . ">;tag=devfrom1\r\n"
        . "To: <sip:" . SIP_ID . "@" . REALM . ">\r\n"
        . "Call-ID: reg-1@127.0.0.1\r\n"
        . "CSeq: 1 REGISTER\r\n"
        . "Contact: <sip:" . DEV_ID . "@127.0.0.1:{$devPort}>\r\n"
        . "Expires: 3600\r\n"
        . "Max-Forwards: 70\r\n"
        . "User-Agent: FakeGBDevice/1.0\r\n"
        . "Content-Length: 0\r\n\r\n";
    $dev->sendto('127.0.0.1', SERVER_PORT, $reg);

    $r = $recvSip(3.0);
    check('收到 401', $r !== null && $r['status_code'] === 401);
    check('401 带 WWW-Authenticate nonce', $r !== null && str_contains((string)($r['header_map']['www-authenticate'] ?? ''), 'nonce="testnonce123"'));

    // ========== 2. REGISTER 二次（Digest）→ 200 ==========
    echo "== REGISTER Digest 认证 ==\n";
    $uri = 'sip:' . SIP_ID . '@' . REALM;
    $ha1 = md5(DEV_ID . ':' . REALM . ':' . PASSWORD);
    $ha2 = md5('REGISTER:' . $uri);
    $resp = md5($ha1 . ':testnonce123:' . $ha2);
    $reg2 = str_replace("From: <sip:" . DEV_ID . "@" . REALM . ">;tag=devfrom1\r\n", "From: <sip:" . DEV_ID . "@" . REALM . ">;tag=devfrom2\r\n", $reg);
    $reg2 = str_replace("Call-ID: reg-1@127.0.0.1\r\n", "Call-ID: reg-2@127.0.0.1\r\n", $reg2);
    $reg2 = str_replace("CSeq: 1 REGISTER\r\n", "CSeq: 2 REGISTER\r\n", $reg2);
    $reg2 = str_replace("Content-Length: 0\r\n\r\n",
        "Authorization: Digest username=\"" . DEV_ID . "\", realm=\"" . REALM . "\", nonce=\"testnonce123\", uri=\"{$uri}\", response=\"{$resp}\", algorithm=MD5\r\nContent-Length: 0\r\n\r\n", $reg2);
    $dev->sendto('127.0.0.1', SERVER_PORT, $reg2);

    $r = $recvSip(3.0);
    check('收到 REGISTER 200', $r !== null && $r['status_code'] === 200);
    check('200 带 Expires', $r !== null && ($r['header_map']['expires'] ?? '') === '3600');
    check('引擎事件侧 Via 注入 received/rport', str_contains((string)($state['via_header'] ?? ''), 'received=127.0.0.1'));
    check('getConnection 源端口=设备端口', ($state['register_conn']['port'] ?? 0) === $devPort);

    // ========== 3. MESSAGE Keepalive → 200 ==========
    echo "== MESSAGE 心跳 ==\n";
    $xml = '<?xml version="1.0"?>' . "\r\n<Notify>\r\n<CmdType>Keepalive</CmdType>\r\n<SN>1</SN>\r\n<DeviceID>" . DEV_ID . "</DeviceID>\r\n<Status>OK</Status>\r\n</Notify>";
    $msg = "MESSAGE sip:" . SIP_ID . "@" . REALM . " SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:{$devPort};rport;branch=z9hG4bKdev002\r\n"
        . "From: <sip:" . DEV_ID . "@" . REALM . ">;tag=devfrom3\r\n"
        . "To: <sip:" . SIP_ID . "@" . REALM . ">\r\n"
        . "Call-ID: msg-1@127.0.0.1\r\n"
        . "CSeq: 1 MESSAGE\r\n"
        . "Content-Type: Application/MANSCDP+xml\r\n"
        . 'Content-Length: ' . strlen($xml) . "\r\n\r\n" . $xml;
    $dev->sendto('127.0.0.1', SERVER_PORT, $msg);
    $r = $recvSip(3.0);
    check('收到 MESSAGE 200', $r !== null && $r['status_code'] === 200);
    check('引擎收到 XML 正文', str_contains((string)($state['message_body'] ?? ''), 'Keepalive'));

    // ========== 4. 服务端 sendMessage（realm URI → 注册表解析） ==========
    echo "== 服务端 sendMessage（realm 解析） ==\n";
    $cid = $sip->sendMessage('sip:' . DEV_ID . '@' . REALM, '<?xml version="1.0"?><Query><CmdType>Catalog</CmdType></Query>', 'Application/MANSCDP+xml');
    check('sendMessage 返回 cid', is_int($cid) && $cid > 0);
    $r = $recvSip(3.0);
    check('设备收到 MESSAGE（经注册表解析到设备地址）', $r !== null && $r['method'] === 'MESSAGE');
    if ($r) {
        $dev->sendto('127.0.0.1', SERVER_PORT, $devResponse($r, 200, 'OK'));
    }
    Coroutine::sleep(0.3);
    check('MESSAGE 200 事件类型', ($state['last_response_type'] ?? 0) === EXOSIP_MESSAGE_ANSWERED);

    // ========== 5. INVITE 点播全流程 ==========
    echo "== INVITE 点播 ==\n";
    $sdpReq = "v=0\r\n"
        . "o:" . SIP_ID . " 0 0 IN IP4 127.0.0.1\r\n"
        . "s=Play\r\n"
        . "c=IN IP4 127.0.0.1\r\n"
        . "t=0 0\r\n"
        . "m=video 30000 TCP/RTP/AVP 96 97 98\r\n"
        . "a=recvonly\r\n"
        . "a=setup:passive\r\n"
        . "a=connection:new\r\n"
        . "y=0100000123\r\n";
    $callId = $sip->sendInvite('sip:' . DEV_ID . '@127.0.0.1:' . $devPort, $sdpReq, [
        'Subject' => DEV_ID . ':' . DEV_ID . ',' . SIP_ID . ':0',
    ]);
    check('sendInvite 返回 cid', is_int($callId) && $callId > 0);

    $r = $recvSip(3.0);
    check('设备收到 INVITE', $r !== null && $r['method'] === 'INVITE');
    check('INVITE Subject 正确', $r !== null && str_contains((string)($r['header_map']['subject'] ?? ''), DEV_ID));

    $sdpAns = "v=0\r\n"
        . "o=" . DEV_ID . " 0 0 IN IP4 127.0.0.1\r\n"
        . "s=Play\r\n"
        . "c=IN IP4 127.0.0.1\r\n"
        . "t=0 0\r\n"
        . "m=video 15060 TCP/RTP/AVP 96\r\n"
        . "a=sendonly\r\n"
        . "a=setup:active\r\n"
        . "y=0100000456\r\n";
    $ok200 = $devResponse($r, 200, 'OK', ['Content-Type' => 'application/sdp'], $sdpAns);
    $dev->sendto('127.0.0.1', SERVER_PORT, $ok200);

    $r = $recvSip(3.0);
    check('设备收到 ACK', $r !== null && $r['method'] === 'ACK');
    Coroutine::sleep(0.3);
    $inv = $state['invite_events'][0] ?? null;
    check('CALL_ANSWERED 事件触发', $inv !== null);
    check('事件 callId=sendInvite 返回值', ($inv['callId'] ?? 0) === $callId);
    check('事件 dialogId>0', ($inv['dialogId'] ?? 0) > 0);
    check('SDP y= 解析（gb28181.ssrc）', ($inv['ssrc'] ?? '') === '0100000456');
    check('SDP connection.addr 解析', ($inv['conn'] ?? '') === '127.0.0.1');

    // 重传 200 OK → 引擎再次触发 CALL_ANSWERED（与 eXosip 一致，业务层 processedInviteCallIds 去重）
    $dev->sendto('127.0.0.1', SERVER_PORT, $ok200);
    $r = $recvSip(2.0);
    check('200 重传后仍收到 ACK', $r !== null && $r['method'] === 'ACK');
    Coroutine::sleep(0.2);
    check('重传事件 dialogId 一致（业务层去重依据）', count($state['invite_events'] ?? []) === 2
        && ($state['invite_events'][1]['dialogId'] ?? 0) === ($state['invite_events'][0]['dialogId'] ?? -1));

    // 设备发 BYE → 自动 200 + onBye（callId 关联）
    echo "== BYE 结束会话 ==\n";
    $toHdr = 'To: <sip:' . SIP_ID . '@' . REALM . '>;tag=' . 'XXX';   // 占位，下面用真实 tag
    // 从 INVITE 请求中取 From tag（服务端 tag）
    $inviteFromTag = '';
    if (isset($r)) { /* ACK 已消费，直接重建 BYE 所需头 */ }
    // 重新拿 INVITE（引擎重传过，但已被消费）——改由 ok200 的 To tag 提取设备 tag、From 提取服务端 tag
    $p200 = SipCodec::parse($ok200);
    $serverTag = SipCodec::parseUri('x'); // noop 防未用警告
    $fromTag = '';
    if (preg_match('/tag=([^;>\s]+)/', (string)($p200['header_map']['from'] ?? ''), $m1)) {
        $fromTag = $m1[1];      // 服务端 From tag（出站 INVITE 的本地 tag）
    }
    $devTag = '';
    if (preg_match('/tag=([^;>\s]+)/', (string)($p200['header_map']['to'] ?? ''), $m2)) {
        $devTag = $m2[1];       // 设备 To tag
    }
    $bye = "BYE sip:" . SIP_ID . "@" . REALM . " SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:{$devPort};rport;branch=z9hG4bKdev003\r\n"
        . "From: <sip:" . DEV_ID . "@" . REALM . ">;tag={$devTag}\r\n"
        . "To: <sip:" . SIP_ID . "@" . REALM . ">;tag={$fromTag}\r\n"
        . "Call-ID: " . ($p200['header_map']['call-id'] ?? '') . "\r\n"
        . "CSeq: 1 BYE\r\n"
        . "Content-Length: 0\r\n\r\n";
    $dev->sendto('127.0.0.1', SERVER_PORT, $bye);
    $r = $recvSip(3.0);
    check('BYE 自动 200', $r !== null && $r['status_code'] === 200);
    Coroutine::sleep(0.3);
    check('onBye 事件 callId 关联会话', ($state['bye_call_id'] ?? 0) === $callId);
    check('onBye 事件 dialogId', ($state['bye_dialog_id'] ?? 0) === ($inv['dialogId'] ?? -1));

    // ========== 6. SUBSCRIBE 订阅流 ==========
    echo "== SUBSCRIBE 订阅 ==\n";
    $subBody = '<?xml version="1.0"?><Query><CmdType>Catalog</CmdType><SN>2</SN></Query>';
    $sid = $sip->subscribe('sip:' . DEV_ID . '@127.0.0.1:' . $devPort, 'Catalog', 3600, $subBody);
    check('subscribe 返回 sid', is_int($sid) && $sid > 0);

    $r = $recvSip(3.0);
    check('设备收到 SUBSCRIBE', $r !== null && $r['method'] === 'SUBSCRIBE');
    check('Event 头', $r !== null && ($r['header_map']['event'] ?? '') === 'Catalog');
    $subCallId = $r['header_map']['call-id'] ?? '';
    $dev->sendto('127.0.0.1', SERVER_PORT, $devResponse($r, 200, 'OK'));
    Coroutine::sleep(0.3);
    check('SUBSCRIPTION_ANSWERED 事件 sid', ($state['sub_answer_sid'] ?? 0) === $sid);
    $subDid = $state['sub_answer_did'] ?? 0;
    check('订阅对话建立 dialogId>0', $subDid > 0);

    // refreshSubscribe（业务传 dialog_id）
    $ok = $sip->refreshSubscribe($subDid, 7200);
    check('refreshSubscribe 发送成功', $ok === true);
    $r = $recvSip(3.0);
    check('设备收到续订 SUBSCRIBE（同 Call-ID）', $r !== null && $r['method'] === 'SUBSCRIBE' && ($r['header_map']['call-id'] ?? '') === $subCallId);
    check('续订 Expires=7200', $r !== null && ($r['header_map']['expires'] ?? '') === '7200');
    if ($r) {
        $dev->sendto('127.0.0.1', SERVER_PORT, $devResponse($r, 200, 'OK'));
    }

    // cancelSubscribe
    $ok = $sip->cancelSubscribe($sid);
    check('cancelSubscribe 发送成功', $ok === true);
    $r = $recvSip(3.0);
    check('设备收到取消订阅（Expires=0）', $r !== null && $r['method'] === 'SUBSCRIBE' && ($r['header_map']['expires'] ?? '') === '0');

    // ========== 7. 设备入站 SUBSCRIBE ==========
    echo "== 设备入站 SUBSCRIBE ==\n";
    $subIn = "SUBSCRIBE sip:" . SIP_ID . "@" . REALM . " SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:{$devPort};rport;branch=z9hG4bKdev004\r\n"
        . "From: <sip:" . DEV_ID . "@" . REALM . ">;tag=devsub1\r\n"
        . "To: <sip:" . SIP_ID . "@" . REALM . ">\r\n"
        . "Call-ID: subin-1@127.0.0.1\r\n"
        . "CSeq: 1 SUBSCRIBE\r\n"
        . "Event: Catalog\r\n"
        . "Expires: 300\r\n"
        . "Content-Length: 0\r\n\r\n";
    $dev->sendto('127.0.0.1', SERVER_PORT, $subIn);
    $r = $recvSip(3.0);
    check('入站 SUBSCRIBE 收到 200', $r !== null && $r['status_code'] === 200);
    Coroutine::sleep(0.2);
    check('onSubscribe 事件 Expires', ($state['inbound_subscribe'] ?? 0) === 300);

    // ========== 8. Task / Pipe 管道 ==========
    echo "== Task / Pipe ==\n";
    $sip->addTask(['x' => 21]);
    $sip->sendToWorker(['action' => 'ptz_control', 'device_id' => DEV_ID]);
    Coroutine::sleep(0.5);
    check('onTask→onTaskFinish 结果回传', ($state['task_result']['doubled'] ?? 0) === 42);
    check('sendToWorker→onPipeMessage', ($state['pipe_message']['action'] ?? '') === 'ptz_control');

    // ========== 9. 停止 ==========
    $sip->stop();
    echo "\n";
});

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail > 0 ? 1 : 0);
