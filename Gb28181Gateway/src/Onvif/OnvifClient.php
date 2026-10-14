<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Onvif;

/**
 * ONVIF SOAP 客户端（协程安全：HTTP 走 curl，被 SWOOLE_HOOK_CURL 协程化）
 *
 * 鉴权：WS-UsernameToken PasswordDigest（SHA1(nonce + created + password)），
 * 自动先 GetSystemDateAndTime 校时消除设备时间偏差导致的鉴权失败。
 * 每个实例对应一台设备（endpoint + 凭据），capabilities/profiles 带缓存。
 */
final class OnvifClient
{
    private string $endpoint;

    private string $username;

    private string $password;

    private int $httpTimeout;

    private $logger;

    /** @var array<string, mixed>|null GetCapabilities 结果缓存 */
    private ?array $capabilities = null;

    /** @var int 设备与本地 UTC 时间偏差（秒） */
    private int $timeOffset = 0;

    /** @var bool 是否已校时（401 时自动重试一次的标记） */
    private bool $timeSynced = false;

    public function __construct(string $endpoint, string $username = 'admin', string $password = '', int $httpTimeout = 5, $logger = null)
    {
        $this->endpoint   = rtrim($endpoint, '/');
        $this->username   = $username;
        $this->password   = $password;
        $this->httpTimeout = $httpTimeout;
        $this->logger     = $logger;
    }

    // =====================================================================
    // 设备服务（Device Service，默认 endpoint 即设备服务地址）
    // =====================================================================

    /** @return array{manufacturer?,model?,firmware_version?,serial_number?,hardware_id?} */
    public function getDeviceInformation() : array
    {
        $xml = $this->call($this->endpoint, 'GetDeviceInformation', 'http://www.onvif.org/ver10/device/wsdl');
        $body = $xml->xpath('//tdf:GetDeviceInformationResponse')[0] ?? null;
        if (!$body) {
            return [];
        }
        return [
            'manufacturer'     => (string)($body->Manufacturer ?? ''),
            'model'            => (string)($body->Model ?? ''),
            'firmware_version' => (string)($body->FirmwareVersion ?? ''),
            'serial_number'    => (string)($body->SerialNumber ?? ''),
            'hardware_id'      => (string)($body->HardwareId ?? ''),
        ];
    }

    /**
     * GetCapabilities：返回并缓存 Media/PTZ 服务地址
     * @return array{media_xaddr?:string, ptz_xaddr?:string, events_xaddr?:string}
     */
    public function getCapabilities(bool $refresh = false) : array
    {
        if ($this->capabilities !== null && !$refresh) {
            return $this->capabilities;
        }
        $xml  = $this->call($this->endpoint, 'GetCapabilities', 'http://www.onvif.org/ver10/device/wsdl', '<tdf:Category>All</tdf:Category>');
        $caps = [
            'media_xaddr' => (string)($xml->xpath('//tt:Media/XAddr')[0] ?? $this->endpoint),
            'ptz_xaddr'   => (string)($xml->xpath('//tt:PTZ/XAddr')[0] ?? ''),
            'events_xaddr' => (string)($xml->xpath('//tt:Events/XAddr')[0] ?? ''),
        ];
        $this->capabilities = $caps;
        return $caps;
    }

    // =====================================================================
    // 媒体服务
    // =====================================================================

    /**
     * GetProfiles：取全部 Profile（token/名称/分辨率/编码）
     * @return array<int, array{token:string,name:string,resolution:string,encoding:string}>
     */
    public function getProfiles() : array
    {
        $mediaUrl = $this->getCapabilities()['media_xaddr'] ?? $this->endpoint;
        $xml = $this->call($mediaUrl, 'GetProfiles', 'http://www.onvif.org/ver10/media/wsdl');
        $out = [];
        foreach ($xml->xpath('//trt:Profiles') as $p) {
            $w = (int)($p->VideoEncoderConfiguration->Resolution->Width ?? 0);
            $h = (int)($p->VideoEncoderConfiguration->Resolution->Height ?? 0);
            $out[] = [
                'token'      => (string)($p['token'] ?? ''),
                'name'       => (string)($p->Name ?? ''),
                'resolution' => $w > 0 ? "{$w}x{$h}" : '',
                'encoding'   => (string)($p->VideoEncoderConfiguration->Encoding ?? ''),
            ];
        }
        return $out;
    }

    /**
     * GetStreamUri：取 RTSP 播放地址
     * @return array{uri:string, stream_type?:string, invalid_after_connect?:bool}
     */
    public function getStreamUri(string $profileToken, string $protocol = 'RTSP', string $transport = 'UDP') : array
    {
        $mediaUrl = $this->getCapabilities()['media_xaddr'] ?? $this->endpoint;
        $body = '<trt:ProfileToken>' . htmlspecialchars($profileToken, ENT_XML1) . '</trt:ProfileToken>'
            . '<trt:StreamSetup><tt:Stream>RTP-Unicast</tt:Stream>'
            . '<tt:Transport><tt:Protocol>' . htmlspecialchars($protocol, ENT_XML1) . '</tt:Protocol></tt:Transport>'
            . '</trt:StreamSetup>';
        $xml = $this->call($mediaUrl, 'GetStreamUri', 'http://www.onvif.org/ver10/media/wsdl', $body);
        $uri = (string)($xml->xpath('//trt:GetStreamUriResponse/trt:MediaUri')[0] ?? '');
        return ['uri' => $uri, 'stream_type' => $transport];
    }

    /** @return array{uri:string} 快照地址（HTTP 抓图） */
    public function getSnapshotUri(string $profileToken) : array
    {
        $mediaUrl = $this->getCapabilities()['media_xaddr'] ?? $this->endpoint;
        $body = '<trt:ProfileToken>' . htmlspecialchars($profileToken, ENT_XML1) . '</trt:ProfileToken>';
        $xml = $this->call($mediaUrl, 'GetSnapshotUri', 'http://www.onvif.org/ver10/media/wsdl', $body);
        return ['uri' => (string)($xml->xpath('//trt:GetSnapshotUriResponse/trt:MediaUri')[0] ?? '')];
    }

    // =====================================================================
    // PTZ 服务
    // =====================================================================

    /** 连续移动（速度归一化 -1.0 ~ 1.0） */
    public function ptzContinuousMove(string $profileToken, float $panSpeed = 0.0, float $tiltSpeed = 0.0, float $zoomSpeed = 0.0) : bool
    {
        $body = $this->ptzProfileHeader($profileToken)
            . '<tt:PanTilt x="' . $panSpeed . '" y="' . $tiltSpeed . '" xmlns="http://www.onvif.org/ver10/schema"/>'
            . ($zoomSpeed != 0.0 ? '<tt:Zoom x="' . $zoomSpeed . '" xmlns="http://www.onvif.org/ver10/schema"/>' : '');
        $this->ptzCall('ContinuousMove', $profileToken, $body);
        return true;
    }

    public function ptzStop(string $profileToken, bool $panTilt = true, bool $zoom = true) : bool
    {
        $body = $this->ptzProfileHeader($profileToken)
            . '<tptz:PanTilt stop="' . var_export($panTilt, true) . '" xmlns="http://www.onvif.org/ver10/schema"/>'
            . '<tptz:Zoom stop="' . var_export($zoom, true) . '" xmlns="http://www.onvif.org/ver10/schema"/>';
        $this->ptzCall('Stop', $profileToken, $body);
        return true;
    }

    /** 绝对定位（归一化坐标） */
    public function ptzAbsoluteMove(string $profileToken, float $pan, float $tilt, float $zoom, float $speed = 0.5) : bool
    {
        $body = $this->ptzProfileHeader($profileToken)
            . '<tt:Position><tt:PanTilt x="' . $pan . '" y="' . $tilt . '" xmlns="http://www.onvif.org/ver10/schema"/><tt:Zoom x="' . $zoom . '" xmlns="http://www.onvif.org/ver10/schema"/></tt:Position>'
            . '<tt:Speed><tt:PanTilt x="' . $speed . '" y="' . $speed . '" xmlns="http://www.onvif.org/ver10/schema"/><tt:Zoom x="' . $speed . '" xmlns="http://www.onvif.org/ver10/schema"/></tt:Speed>';
        $this->ptzCall('AbsoluteMove', $profileToken, $body);
        return true;
    }

    /** 调用预置位 */
    public function ptzGotoPreset(string $profileToken, string $presetToken, float $speed = 0.5) : bool
    {
        $body = $this->ptzProfileHeader($profileToken)
            . '<tptz:PresetToken>' . htmlspecialchars($presetToken, ENT_XML1) . '</tptz:PresetToken>'
            . '<tt:Speed><tt:PanTilt x="' . $speed . '" y="' . $speed . '" xmlns="http://www.onvif.org/ver10/schema"/></tt:Speed>';
        $this->ptzCall('GotoPreset', $profileToken, $body);
        return true;
    }

    /** 设置预置位 */
    public function ptzSetPreset(string $profileToken, string $presetName) : string
    {
        $body = $this->ptzProfileHeader($profileToken)
            . '<tptz:PresetName>' . htmlspecialchars($presetName, ENT_XML1) . '</tptz:PresetName>';
        $xml = $this->ptzCall('SetPreset', $profileToken, $body);
        return (string)($xml->xpath('//tptz:SetPresetResponse/tptz:PresetToken')[0] ?? '');
    }

    /** 获取预置位列表 */
    public function ptzGetPresets(string $profileToken) : array
    {
        $xml = $this->ptzCall('GetPresets', $profileToken, $this->ptzProfileHeader($profileToken));
        $out = [];
        foreach ($xml->xpath('//tptz:GetPresetsResponse/tt:Preset') as $p) {
            $out[] = ['token' => (string)($p['token'] ?? ''), 'name' => (string)($p->Name ?? '')];
        }
        return $out;
    }

    // =====================================================================
    // 系统服务
    // =====================================================================

    /** 无鉴权取设备时间，计算与本地 UTC 偏差 */
    public function syncTime() : bool
    {
        try {
            $xml = $this->call($this->endpoint, 'GetSystemDateAndTime', 'http://www.onvif.org/ver10/device/wsdl', '', false);
            $utc = $xml->xpath('//tt:UTCDateTime')[0] ?? null;
            if ($utc) {
                $devTs = gmmktime((int)$utc->Hour, (int)$utc->Minute, (int)$utc->Second,
                    (int)$utc->Month === 0 ? 1 : (int)$utc->Month, (int)$utc->Day === 0 ? 1 : (int)$utc->Day, (int)$utc->Year);
                if ($devTs > 0) {
                    $this->timeOffset = $devTs - time();
                    $this->timeSynced = true;
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->log("syncTime failed: {$e->getMessage()}", 'DEBUG');
        }
        return false;
    }

    public function reboot() : bool
    {
        $this->call($this->endpoint, 'SystemReboot', 'http://www.onvif.org/ver10/device/wsdl');
        return true;
    }

    public function getTimeOffset() : int
    {
        return $this->timeOffset;
    }

    // =====================================================================
    // SOAP 基础设施
    // =====================================================================

    private function ptzProfileHeader(string $profileToken) : string
    {
        return '<tptz:ProfileToken>' . htmlspecialchars($profileToken, ENT_XML1) . '</tptz:ProfileToken>';
    }

    private function ptzCall(string $op, string $profileToken, string $bodyParams)
    {
        $ptzUrl = $this->getCapabilities()['ptz_xaddr'] ?? '';
        if ($ptzUrl === '') {
            throw new \RuntimeException("device has no PTZ service (profile={$profileToken})");
        }
        return $this->call($ptzUrl, $op, 'http://www.onvif.org/ver20/ptz/wsdl', $bodyParams);
    }

    /**
     * 发送 SOAP 1.2 请求并解析响应
     *
     * @param string $url       服务地址
     * @param string $op        操作名（同时也是响应根元素 {Op}Response）
     * @param string $namespace 操作命名空间（作为请求体容器前缀映射）
     * @param string $bodyParams 操作参数 XML 片段
     * @param bool   $auth      是否携带 WSSE 头
     */
    private function call(string $url, string $op, string $namespace, string $bodyParams = '', bool $auth = true) : \SimpleXMLElement
    {
        $payload = $this->buildEnvelope($op, $namespace, $bodyParams, $auth);
        [$status, , $respBody] = $this->httpPost($url, $payload);

        if ($status === 401 && $auth) {
            // 设备时间偏差导致摘要失效：校时后重试一次
            if (!$this->timeSynced) {
                $this->syncTime();
                $payload = $this->buildEnvelope($op, $namespace, $bodyParams, true);
                [$status, , $respBody] = $this->httpPost($url, $payload);
            } else {
                throw new \RuntimeException("ONVIF 401 unauthorized ({$op})，请检查用户名密码");
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("ONVIF HTTP {$status} on {$op}");
        }

        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($respBody);
        libxml_clear_errors();
        if (!$xml) {
            throw new \RuntimeException("ONVIF 响应解析失败 ({$op})");
        }
        $fault = $xml->xpath('//s:Body/s:Fault | //soap:Body/soap:Fault')[0] ?? null;
        if ($fault) {
            $text = (string)($fault->xpath('.//*[local-name()="Text"]')[0] ?? 'unknown fault');
            throw new \RuntimeException("ONVIF Fault ({$op}): {$text}");
        }
        return $xml;
    }

    private function buildEnvelope(string $op, string $namespace, string $bodyParams, bool $auth) : string
    {
        $ns = match ($namespace) {
            'http://www.onvif.org/ver10/device/wsdl' => 'tdf',
            'http://www.onvif.org/ver10/media/wsdl'  => 'trt',
            'http://www.onvif.org/ver20/ptz/wsdl'    => 'tptz',
            'http://www.onvif.org/ver20/media/wsdl'  => 'tr2',
            default                                   => 'onv',
        };
        $header = '';
        if ($auth) {
            $header = $this->wsseHeader();
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n"
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:' . $ns . '="' . $namespace . '"'
            . ' xmlns:tt="http://www.onvif.org/ver10/schema"'
            . ' xmlns:tptz="http://www.onvif.org/ver20/ptz/wsdl"'
            . ' xmlns:trt="http://www.onvif.org/ver10/media/wsdl"'
            . ' xmlns:tdf="http://www.onvif.org/ver10/device/wsdl">'
            . '<s:Header>' . $header . '</s:Header>'
            . '<s:Body><' . $ns . ':' . $op . '>' . $bodyParams . '</' . $ns . ':' . $op . '></s:Body>'
            . '</s:Envelope>';
    }

    /** WS-UsernameToken PasswordDigest 头 */
    private function wsseHeader() : string
    {
        $nonceRaw = random_bytes(16);
        $nonce    = base64_encode($nonceRaw);
        $ts       = $this->timeOffset + microtime(true);
        $created  = gmdate('Y-m-d\TH:i:s', (int)$ts) . sprintf('.%03dZ', (int)(($ts - (int)$ts) * 1000));
        $digest   = base64_encode(sha1($nonceRaw . $created . $this->password, true));
        return '<Security s:mustUnderstand="1" xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
            . '<UsernameToken u:Id="UntId-' . bin2hex(random_bytes(6)) . '" xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'
            . '<Username>' . htmlspecialchars($this->username, ENT_XML1) . '</Username>'
            . '<Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">' . $digest . '</Password>'
            . '<Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">' . $nonce . '</Nonce>'
            . '<Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">' . $created . '</Created>'
            . '</UsernameToken></Security>';
    }

    /** @return array{0:int,1:string,2:string} [http_code, content_type, body] */
    private function httpPost(string $url, string $payload) : array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException("ONVIF HTTP 请求失败({$url}): {$err}");
        }
        return [$status, '', (string)$body];
    }

    private function log(string $msg, string $level = 'DEBUG') : void
    {
        if ($this->logger !== null) {
            $m = strtolower($level === 'WARNING' ? 'error' : $level);
            if (method_exists($this->logger, $m)) {
                $this->logger->{$m}("[OnvifClient] {$msg}");
            }
        }
    }
}
