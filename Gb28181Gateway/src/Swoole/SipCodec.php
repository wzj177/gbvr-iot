<?php

declare(strict_types = 1);

namespace Gb28181\GateWay\Swoole;

/**
 * SIP 消息编解码器（纯 PHP，不依赖 Swoole，可独立单测）
 *
 * 提供与 php-exosip C 扩展（osip2）一致的解析输出结构：
 * - parseSdp() 返回 native osip2 风格数组：connection.addr / medias[].port|proto|attributes / gb28181.ssrc
 */
final class SipCodec
{
    public const CRLF = "\r\n";

    // =====================================================================
    // 解析
    // =====================================================================

    /**
     * 解析一条完整 SIP 消息（请求或响应）
     *
     * @return array{
     *   is_request: bool,
     *   method: string|null, uri: string|null, status_code: int|null, reason: string|null,
     *   headers: list<array{name: string, value: string}>,
     *   header_map: array<string, string>,   // 小写头名 -> 值（首个）
     *   body: string
     * }
     */
    public static function parse(string $raw) : array
    {
        $raw = str_replace(self::CRLF, "\n", $raw);
        $parts = explode("\n\n", $raw, 2);
        $headStr = $parts[0];
        $body = $parts[1] ?? '';

        $lines = explode("\n", $headStr);
        $startLn = trim(array_shift($lines) ?? '');

        $isRequest = true;
        $method = $uri = null;
        $code = null;
        $reason = null;

        if (preg_match('#^SIP/2\.0\s+(\d{3})\s*(.*)$#i', $startLn, $m)) {
            $isRequest = false;
            $code = (int)$m[1];
            $reason = $m[2];
        } else if (preg_match('#^([A-Za-z]+)\s+(\S+)\s+SIP/2\.0#i', $startLn, $m)) {
            $method = strtoupper($m[1]);
            $uri = $m[2];
        }

        $headers = [];
        $headerMap = [];
        $lastName = null;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            // 头折行（以空白开头，续上一行）
            if ($lastName !== null && ($line[0] === ' ' || $line[0] === "\t")) {
                $idx = count($headers) - 1;
                if ($idx >= 0) {
                    $headers[$idx]['value'] .= ' ' . trim($line);
                    $headerMap[strtolower($headers[$idx]['name'])] = $headers[$idx]['value'];
                }
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            $headers[] = ['name' => $name, 'value' => $value];
            if (!isset($headerMap[$lower])) {
                $headerMap[$lower] = $value;
            }
            $lastName = $name;
        }

        return [
            'is_request'  => $isRequest,
            'method'      => $method,
            'uri'         => $uri,
            'status_code' => $code,
            'reason'      => $reason,
            'headers'     => $headers,
            'header_map'  => $headerMap,
            'body'        => $body,
        ];
    }

    /**
     * 给入站请求的顶层 Via 注入 received/rport 参数（eXosip 标准行为）
     * GB28181Handler 依赖 Via 头中的 received/rport 获取设备实际源地址
     *
     * @param array $msg parse() 的返回值（引用修改）
     */
    public static function injectViaReceived(array &$msg, string $srcIp, int $srcPort) : void
    {
        foreach ($msg['headers'] as $i => $h) {
            if (strcasecmp($h['name'], 'Via') === 0) {
                $v = $h['value'];
                if (stripos($v, 'received=') === false) {
                    $v .= ';received=' . $srcIp;
                }
                if (stripos($v, 'rport=') === false) {
                    $v .= ';rport=' . $srcPort;
                }
                $msg['headers'][$i]['value'] = $v;
                $msg['header_map']['via'] = $v;
                return; // 只处理顶层（第一跳）Via
            }
        }
    }

    // =====================================================================
    // 构建
    // =====================================================================

    /**
     * 基于入站事务请求构建响应（复制 Via/From/To/Call-ID/CSeq）
     *
     * @param array $req parse() 的返回值（已注入 received/rport）
     * @param int $code
     * @param string $reason
     * @param string $toTag 本端 tag（final 响应填充 To tag）
     * @param array $headers 附加头 ['Expires' => 3600, ...]
     * @param string $body
     * @param string $contentType
     * @param string $ua User-Agent
     */
    public static function buildResponse(
        array $msg,
        int $code,
        string $reason,
        string $toTag,
        array $headers,
        string $body,
        string $contentType,
        string $ua
    ) : string
    {
        $lines = ["SIP/2.0 {$code} {$reason}"];

        // Via 全部按原顺序复制（含注入的 received/rport）
        foreach ($msg['headers'] as $h) {
            if (strcasecmp($h['name'], 'Via') === 0) {
                $lines[] = "Via: {$h['value']}";
            }
        }

        $from = self::firstHeader($msg, 'From') ?? '';
        $to   = self::firstHeader($msg, 'To') ?? '';
        if ($code >= 200 && $toTag !== '' && stripos($to, 'tag=') === false) {
            // 兼容三种形态：<...>、<...>;params（如 ;transport=udp）、裸 addr
            if (str_ends_with($to, '>')) {
                $to = substr($to, 0, -1) . '>;tag=' . $toTag;
            } else {
                $to .= ';tag=' . $toTag;
            }
        }
        if ($from !== '') {
            $lines[] = "From: {$from}";
        }
        if ($to !== '') {
            $lines[] = "To: {$to}";
        }
        if (($cid = self::firstHeader($msg, 'Call-ID')) !== null) {
            $lines[] = "Call-ID: {$cid}";
        }
        if (($cseq = self::firstHeader($msg, 'CSeq')) !== null) {
            $lines[] = "CSeq: {$cseq}";
        }

        if ($ua !== '') {
            $lines[] = "User-Agent: {$ua}";
        }

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $lines[] = "{$name}: {$v}";
                }
            } else {
                $lines[] = "{$name}: {$value}";
            }
        }

        if ($body !== '') {
            if ($contentType !== '') {
                $lines[] = "Content-Type: {$contentType}";
            }
            $lines[] = 'Content-Length: ' . strlen($body);
        } else {
            $lines[] = 'Content-Length: 0';
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $body;
    }

    /**
     * 构建出站 SIP 请求
     *
     * @param string $method REGISTER/MESSAGE/INVITE/SUBSCRIBE/BYE/ACK/INFO/NOTIFY
     * @param string $requestUri 请求行 URI（含 ip:port）
     * @param string $fromHeader 完整 From 头（含 tag）
     * @param string $toHeader 完整 To 头
     * @param string $callId Call-ID 字符串
     * @param int $cseq CSeq 序号
     * @param string $viaHost Via/Contact 使用的主机（public_ip 优先）
     * @param int $viaPort Via/Contact 使用的端口
     * @param string $transport UDP|TCP
     * @param array $headers 附加头（Subject/Event/Expires/Content-Type...）
     * @param string $body
     * @param string $ua
     */
    public static function buildRequest(
        string $method,
        string $requestUri,
        string $fromHeader,
        string $toHeader,
        string $callId,
        int $cseq,
        string $viaHost,
        int $viaPort,
        string $transport,
        array $headers,
        string $body,
        string $ua
    ) : string
    {
        $lines = [];
        $lines[] = "{$method} {$requestUri} SIP/2.0";
        $lines[] = "Via: SIP/2.0/{$transport} {$viaHost}:{$viaPort};branch=" . self::branch();
        if ($fromHeader !== '') {
            $lines[] = "From: {$fromHeader}";
        }
        if ($toHeader !== '') {
            $lines[] = "To: {$toHeader}";
        }
        $lines[] = "Call-ID: {$callId}";
        $lines[] = "CSeq: {$cseq} {$method}";
        $lines[] = "Contact: <sip:{$viaHost}:{$viaPort}>";
        $lines[] = 'Max-Forwards: 70';
        if ($ua !== '') {
            $lines[] = "User-Agent: {$ua}";
        }

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $lines[] = "{$name}: {$v}";
                }
            } else if ($value !== null && $value !== '') {
                $lines[] = "{$name}: {$value}";
            }
        }

        if ($body !== '') {
            $ct = $headers['Content-Type'] ?? '';
            if (is_string($ct) && $ct !== '') {
                $lines[] = "Content-Type: {$ct}";
            }
            $lines[] = 'Content-Length: ' . strlen($body);
        } else {
            $lines[] = 'Content-Length: 0';
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $body;
    }

    /**
     * 在已构建的请求文本上追加 Authorization 头（UAC 401 重试）
     */
    public static function appendHeader(string $message, string $name, string $value) : string
    {
        $pos = strrpos($message, 'Content-Length:');
        if ($pos === false) {
            return $message . self::CRLF . "{$name}: {$value}";
        }
        return substr($message, 0, $pos) . "{$name}: {$value}" . self::CRLF . substr($message, $pos);
    }

    public static function branch() : string
    {
        return 'z9hG4bK' . bin2hex(random_bytes(8));
    }

    public static function tag() : string
    {
        return substr(bin2hex(random_bytes(8)), 0, 10);
    }

    public static function callId(string $host) : string
    {
        return bin2hex(random_bytes(8)) . '@' . $host;
    }

    // =====================================================================
    // URI 工具
    // =====================================================================

    /**
     * 解析 sip:user@host[:port][;uri-params] → [user, host, port]
     */
    public static function parseUri(string $uri) : array
    {
        $uri = trim(str_replace(['<', '>'], '', $uri));
        $semi = strpos($uri, ';');
        if ($semi !== false) {
            $uri = substr($uri, 0, $semi);   // 去 URI 参数（含头参数 ;tag= 等调用方自行处理）
        }
        $body = $uri;
        if (stripos($body, 'sip:') === 0) {
            $body = substr($body, 4);
        } else if (stripos($body, 'sips:') === 0) {
            $body = substr($body, 5);
        }
        $user = '';
        $hostport = $body;
        $at = strrpos($body, '@');
        if ($at !== false) {
            $user = substr($body, 0, $at);
            $hostport = substr($body, $at + 1);
        }
        $port = 5060;
        $host = $hostport;
        if (preg_match('/^(.+):(\d+)$/', $hostport, $m)) {
            $host = $m[1];
            $port = (int)$m[2];
        }
        return [$user, $host, $port];
    }

    public static function isIp(string $host) : bool
    {
        return (bool)filter_var($host, FILTER_VALIDATE_IP);
    }

    // =====================================================================
    // SDP 解析（osip2 风格输出 + GB28181 y= 扩展）
    // =====================================================================

    /**
     * @return array|null 结构：
     *  [
     *    'version' => '0',
     *    'origin'  => ['username'=>..,'session_id'=>..,'version'=>..,'nettype'=>..,'addrtype'=>..,'addr'=>..],
     *    'name' => string|null,
     *    'connection' => ['nettype'=>'IN','addrtype'=>'IP4','addr'=>'1.2.3.4'],
     *    'timing' => [['start'=>..,'stop'=>..]],
     *    'medias' => [
     *       ['media'=>'video','port'=>..,'proto'=>'RTP/AVP','fmts'=>[96],'connection'=>?,'attributes'=>['setup'=>'passive','sendonly'=>true,...]]
     *    ],
     *    'attributes' => [...会话级...],
     *    'gb28181' => ['ssrc'=>'0100000001','f'=>'...'],
     *  ]
     */
    public static function parseSdp(?string $sdp) : ?array
    {
        if (!$sdp || trim($sdp) === '') {
            return null;
        }
        $sdp = str_replace(self::CRLF, "\n", $sdp);

        $out = [
            'version'    => null,
            'origin'     => null,
            'name'       => null,
            'connection' => null,
            'timing'     => [],
            'medias'     => [],
            'attributes' => [],
            'gb28181'    => [],
        ];

        $media = null;
        $mediaIdx = -1;
        $gbAttrs = [];
        foreach (explode("\n", $sdp) as $line) {
            $line = trim($line, " \t\r");
            if ($line === '') {
                continue;
            }
            $type = $line[0];
            $val = trim(substr($line, 2));   // 行格式 "x=value"，跳过 "x="
            if ($type === 'm') {
                $media = [
                    'media'      => null,
                    'port'       => 0,
                    'proto'      => 'RTP/AVP',
                    'fmts'       => [],
                    'connection' => null,
                    'attributes' => [],
                ];
                // m=video 6000 TCP/RTP/AVP 96 97 98
                if (preg_match('/^(\S+)\s+(\d+)\s+(\S+)(.*)$/', $val, $m)) {
                    $media['media'] = $m[1];
                    $media['port'] = (int)$m[2];
                    $media['proto'] = $m[3];
                    foreach (preg_split('/\s+/', trim($m[4] ?? '')) as $fmt) {
                        if ($fmt !== '') {
                            $media['fmts'][] = $fmt;
                        }
                    }
                }
                $out['medias'][] = $media;
                $mediaIdx = count($out['medias']) - 1;
                continue;
            }
            if ($mediaIdx >= 0) {
                switch ($type) {
                    case 'c':
                        $out['medias'][$mediaIdx]['connection'] = self::parseConn($val);
                        break;
                    case 'a':
                        $out['medias'][$mediaIdx]['attributes'] += self::parseAttr($val, $gbAttrs);
                        break;
                    case 'y':
                    case 'f':   // GB28181 扩展行（y=SSRC / f=编码参数），可出现在媒体级
                        $gbAttrs[$type === 'y' ? 'ssrc' : 'f'] = $val;
                        break;
                }
                continue;
            }
            switch ($type) {
                case 'v':
                    $out['version'] = $val;
                    break;
                case 'o':
                    // o=34020000001320000001 0 0 IN IP4 192.168.1.64
                    $p = preg_split('/\s+/', $val);
                    if (count($p) >= 6) {
                        $out['origin'] = [
                            'username'   => $p[0],
                            'session_id' => $p[1],
                            'version'    => $p[2],
                            'nettype'    => $p[3],
                            'addrtype'   => $p[4],
                            'addr'       => $p[5],
                        ];
                    }
                    break;
                case 's':
                    $out['name'] = $val;
                    break;
                case 'c':
                    $out['connection'] = self::parseConn($val);
                    break;
                case 't':
                    $p = preg_split('/\s+/', $val);
                    $out['timing'][] = ['start' => $p[0] ?? 0, 'stop' => $p[1] ?? 0];
                    break;
                case 'a':
                    $out['attributes'] += self::parseAttr($val, $gbAttrs);
                    break;
                case 'y':
                case 'f':   // GB28181 扩展行，可出现在会话级
                    $gbAttrs[$type === 'y' ? 'ssrc' : 'f'] = $val;
                    break;
            }
        }

        // y= 也可能出现在媒体级，parseAttr 已收集进 $gbAttrs
        if (!empty($gbAttrs)) {
            $out['gb28181'] = $gbAttrs;
        }
        // y= 出现在会话级时同样被 parseAttr 收集；兼容 y= 在媒体级 attributes 中的情况
        foreach ($out['medias'] as $mm) {
            if (isset($mm['attributes']['y']) && !isset($out['gb28181']['ssrc'])) {
                $out['gb28181']['ssrc'] = $mm['attributes']['y'];
            }
            if (isset($mm['attributes']['f']) && !isset($out['gb28181']['f'])) {
                $out['gb28181']['f'] = $mm['attributes']['f'];
            }
        }

        if ($out['version'] === null && empty($out['medias']) && $out['origin'] === null) {
            return null;
        }
        return $out;
    }

    private static function parseConn(string $val) : array
    {
        // IN IP4 192.168.1.64
        $p = preg_split('/\s+/', $val);
        return [
            'nettype'  => $p[0] ?? 'IN',
            'addrtype' => $p[1] ?? 'IP4',
            'addr'     => $p[2] ?? '',
        ];
    }

    /**
     * 解析 a= 行：
     * - "flag"        → ['flag' => true]
     * - "k:v"         → ['k' => 'v']（rtpmap/fmtp 保持整体字符串）
     * - "y=0100000001"/"f=..."（GB28181 扩展）→ 同时收集进 $gb
     */
    private static function parseAttr(string $val, array &$gb) : array
    {
        $pos = strpos($val, ':');
        if ($pos !== false) {
            $k = substr($val, 0, $pos);
            $v = substr($val, $pos + 1);
            if ($k === 'y' || $k === 'f') {
                $key = $k === 'y' ? 'ssrc' : 'f';
                $gb[$key] = $v;
            }
            return [$k => $v];
        }
        $pos2 = strpos($val, '=');
        if ($pos2 !== false) {
            $k = substr($val, 0, $pos2);
            $v = substr($val, $pos2 + 1);
            if ($k === 'y' || $k === 'f') {
                $key = $k === 'y' ? 'ssrc' : 'f';
                $gb[$key] = $v;
            }
            return [$k => $v];
        }
        return [$val => true];
    }

    // =====================================================================
    // 其他
    // =====================================================================

    public static function firstHeader(array $msg, string $name) : ?string
    {
        foreach ($msg['headers'] as $h) {
            if (strcasecmp($h['name'], $name) === 0) {
                return $h['value'];
            }
        }
        return null;
    }

    /**
     * 解析 Digest 挑战头（WWW-Authenticate / Authorization）为 key=>value
     */
    public static function parseDigestParams(string $header) : array
    {
        $header = trim(preg_replace('/^Digest\s+/i', '', $header) ?? $header);
        $out = [];
        if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"|(\w+)\s*=\s*([^\s,]+)/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $k = $m[1] !== '' ? $m[1] : $m[3];
                $v = $m[1] !== '' ? $m[2] : $m[4];
                if ($k !== '') {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * 优先 UTF-8，失败按 GBK 转码（兼容 GB2312 设备）
     */
    public static function decodeCharset(string $data) : string
    {
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $data);
        return $converted === false ? $data : $converted;
    }
}
