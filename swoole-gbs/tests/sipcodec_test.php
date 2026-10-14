<?php

declare(strict_types=1);

/**
 * SipCodec 纯 PHP 单元测试（不依赖 Swoole，本机直接跑）
 * 用法：php swoole-gbs/tests/sipcodec_test.php
 */

require_once __DIR__ . '/../../Gb28181Gateway/src/Swoole/SipCodec.php';

use Gb28181\GateWay\Swoole\SipCodec;

$pass = 0;
$fail = 0;

function check(string $name, bool $cond) : void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✔ {$name}\n";
    } else {
        $fail++;
        echo "  ✘ {$name}\n";
    }
}

echo "== SipCodec::parse ==\n";

$register = "REGISTER sip:34020000002000000001@3402000000 SIP/2.0\r\n"
    . "Via: SIP/2.0/UDP 192.168.1.64:5060;rport;branch=z9hG4bK1378834726\r\n"
    . "From: <sip:34020000001320000001@3402000000>;tag=1023535758\r\n"
    . "To: <sip:34020000002000000001@3402000000>\r\n"
    . "Call-ID: 20260101@192.168.1.64\r\n"
    . "CSeq: 1 REGISTER\r\n"
    . "Contact: <sip:34020000001320000001@192.168.1.64:5060>\r\n"
    . "Max-Forwards: 70\r\n"
    . "Expires: 3600\r\n"
    . "User-Agent: IP Camera\r\n"
    . "Content-Length: 0\r\n"
    . "\r\n";

$msg = SipCodec::parse($register);
check('is_request', $msg['is_request'] === true);
check('method REGISTER', $msg['method'] === 'REGISTER');
check('request uri', $msg['uri'] === 'sip:34020000002000000001@3402000000');
check('header map call-id', ($msg['header_map']['call-id'] ?? '') === '20260101@192.168.1.64');
check('header map content-type 缺省', !isset($msg['header_map']['content-type']));
check('expires', ($msg['header_map']['expires'] ?? '') === '3600');

// Via received/rport 注入
SipCodec::injectViaReceived($msg, '10.20.2.95', 55555);
$via = $msg['header_map']['via'];
check('via 注入 received', str_contains($via, 'received=10.20.2.95'));
check('via 注入 rport', str_contains($via, 'rport=55555'));
check('via 保留 branch', str_contains($via, 'z9hG4bK1378834726'));

// 响应解析
$resp200 = "SIP/2.0 200 OK\r\n"
    . "Via: SIP/2.0/UDP 192.168.1.64:5060;rport;branch=z9hG4bK1\r\n"
    . "From: <sip:a@b>;tag=x\r\nTo: <sip:c@d>;tag=y\r\n"
    . "Call-ID: cid@x\r\nCSeq: 1 REGISTER\r\n"
    . "Content-Length: 0\r\n\r\n";
$r = SipCodec::parse($resp200);
check('response is_request=false', $r['is_request'] === false);
check('response code', $r['status_code'] === 200);

// MESSAGE 带 GB2312 XML 正文
$xml = '<?xml version="1.0" encoding="GB2312"?>' . "\r\n<Notify>\r\n<CmdType>Keepalive</CmdType>\r\n</Notify>";
$mess = "MESSAGE sip:34020000002000000001@3402000000 SIP/2.0\r\n"
    . "Via: SIP/2.0/UDP 192.168.1.64:5060;rport;branch=z9hG4bK2\r\n"
    . "From: <sip:34020000001320000001@3402000000>;tag=t1\r\n"
    . "To: <sip:34020000002000000001@3402000000>\r\n"
    . "Call-ID: c2\r\nCSeq: 2 MESSAGE\r\n"
    . "Content-Type: Application/MANSCDP+xml\r\n"
    . 'Content-Length: ' . strlen($xml) . "\r\n\r\n" . $xml;
$m2 = SipCodec::parse($mess);
check('message body 提取', str_contains((string)$m2['body'], 'Keepalive'));
check('message content-type', ($m2['header_map']['content-type'] ?? '') === 'Application/MANSCDP+xml');

echo "== SipCodec::buildResponse ==\n";

$built = SipCodec::buildResponse($msg, 401, 'Unauthorized', '', ['WWW-Authenticate' => 'Digest realm="3402000000", nonce="n1", algorithm=MD5'], '', '', 'UA/1.0');
check('401 状态行', str_starts_with($built, "SIP/2.0 401 Unauthorized\r\n"));
check('401 Via 复制含注入', str_contains($built, 'received=10.20.2.95'));
check('401 WWW-Authenticate', str_contains($built, 'nonce="n1"'));

$built200 = SipCodec::buildResponse($msg, 200, 'OK', '99988877', ['Expires' => '3600'], '', '', 'UA/1.0');
check('200 To tag 补齐', str_contains($built200, ';tag=99988877'));
check('200 Expires 头', str_contains($built200, "Expires: 3600"));
check('200 From 保留原 tag', str_contains($built200, 'tag=1023535758'));

// To 头无尖括号场景（部分设备）
$msg2 = SipCodec::parse(str_replace(['To: <sip:34020000002000000001@3402000000>'], ['To: sip:34020000002000000001@3402000000'], $register));
$b2 = SipCodec::buildResponse($msg2, 200, 'OK', '777', [], '', '', 'UA');
check('200 裸 To 头补 tag', str_contains($b2, 'To: sip:34020000002000000001@3402000000;tag=777'));

echo "== SipCodec::buildRequest ==\n";

$req = SipCodec::buildRequest(
    'INVITE', 'sip:34020000001320000001@192.168.1.64:5060',
    '<sip:34020000002000000001@3402000000>;tag=FT1',
    '<sip:34020000001320000001@3402000000>',
    'cid-1@host', 5, '10.20.2.95', 15060, 'UDP',
    ['Subject' => '34020000001320000001:34020000001320000001,34020000002000000001:0', 'Content-Type' => 'application/sdp'],
    "v=0\r\no=... 0 0 IN IP4 10.20.2.95\r\n", 'UA/1.0'
);
$p = SipCodec::parse($req);
check('INVITE 请求行', $p['method'] === 'INVITE' && $p['uri'] === 'sip:34020000001320000001@192.168.1.64:5060');
check('INVITE branch 魔数', str_contains(($p['header_map']['via'] ?? ''), 'z9hG4bK'));
check('INVITE CSeq', ($p['header_map']['cseq'] ?? '') === '5 INVITE');
check('INVITE Content-Length 正确', str_contains($req, 'Content-Length: ' . strlen("v=0\r\no=... 0 0 IN IP4 10.20.2.95\r\n")));

echo "== SipCodec::parseSdp ==\n";

$sdp = "v=0\r\n"
    . "o=34020000001320000001 1073741824 1073741824 IN IP4 192.168.1.64\r\n"
    . "s=Play\r\n"
    . "c=IN IP4 192.168.1.64\r\n"
    . "t=0 0\r\n"
    . "m=video 6000 TCP/RTP/AVP 96 97 98\r\n"
    . "a=recvonly\r\n"
    . "a=setup:active\r\n"
    . "a=connection:new\r\n"
    . "a=rtpmap:96 PS/90000\r\n"
    . "y=0100000001\r\n";
$s = SipCodec::parseSdp($sdp);
check('sdp connection.addr', ($s['connection']['addr'] ?? '') === '192.168.1.64');
check('sdp medias[0].port', ($s['medias'][0]['port'] ?? 0) === 6000);
check('sdp medias[0].proto', ($s['medias'][0]['proto'] ?? '') === 'TCP/RTP/AVP');
check('sdp attributes.setup', ($s['medias'][0]['attributes']['setup'] ?? '') === 'active');
check('sdp attributes.recvonly 布尔', ($s['medias'][0]['attributes']['recvonly'] ?? false) === true);
check('sdp gb28181.ssrc', ($s['gb28181']['ssrc'] ?? '') === '0100000001');
check('sdp origin.addr', ($s['origin']['addr'] ?? '') === '192.168.1.64');

// sendonly 判定（对讲 SDP）
$sdp2 = "v=0\r\no=x 0 0 IN IP4 1.1.1.1\r\ns=Talk\r\nc=IN IP4 1.1.1.1\r\nt=0 0\r\nm=audio 6000 RTP/AVP 8\r\na=sendonly\r\na=setup:passive\r\ny=0200000001\r\n";
$s2 = SipCodec::parseSdp($sdp2);
check('sdp sendonly 布尔', ($s2['medias'][0]['attributes']['sendonly'] ?? false) === true);
check('sdp audio y=ssrc', ($s2['gb28181']['ssrc'] ?? '') === '0200000001');
check('sdp 空/非法返回 null', SipCodec::parseSdp('') === null && SipCodec::parseSdp('garbage') === null);

echo "== SipCodec::parseUri / parseDigestParams ==\n";

check('uri ip:port', SipCodec::parseUri('sip:34020000001320000001@192.168.1.64:5060') === ['34020000001320000001', '192.168.1.64', 5060]);
check('uri realm 无端口', SipCodec::parseUri('sip:34020000001320000001@3402000000') === ['34020000001320000001', '3402000000', 5060]);
check('uri 带尖括号+参数', SipCodec::parseUri('<sip:dev@1.2.3.4:6060>;transport=udp') === ['dev', '1.2.3.4', 6060]);

$dp = SipCodec::parseDigestParams('Digest username="34020000001320000001", realm="3402000000", nonce="4e56", uri="sip:34020000002000000001@3402000000", response="abc", algorithm=MD5');
check('digest username', ($dp['username'] ?? '') === '34020000001320000001');
check('digest response', ($dp['response'] ?? '') === 'abc');
check('digest algorithm 无引号', ($dp['algorithm'] ?? '') === 'MD5');

echo "== SipCodec::decodeCharset ==\n";
$gbk = iconv('UTF-8', 'GBK', "MESSAGE ...\r\n<Name>中文设备</Name>");
check('GBK 转 UTF-8', mb_check_encoding(SipCodec::decodeCharset($gbk), 'UTF-8'));
check('UTF-8 原样', SipCodec::decodeCharset($register) === $register);

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail > 0 ? 1 : 0);
