<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Swoole;

/**
 * SIP 事件对象 —— php-exosip C 扩展 \SipEvent 的纯 PHP 等价物
 *
 * 由入口通过 class_alias() 映射为全局 \SipEvent，
 * getter 契约与 exosip.stub.php 完全一致，业务层（GB28181Handler 等）零改动。
 */
class SipEvent
{
    /** @var array 事件上下文：type/code/tid/callId/dialogId/subscriptionId/method/fromUri/toUri/requestUri/body/contentType/headers/expires/connection/transport */
    private array $data;

    private ?array $sdpCache = null;

    private bool $sdpParsed = false;

    public function __construct(array $data = [])
    {
        $this->data = array_merge([
            'type'           => 0,
            'code'           => 0,
            'tid'            => 0,
            'callId'         => 0,
            'dialogId'       => 0,
            'subscriptionId' => 0,
            'method'         => null,
            'fromUri'        => null,
            'toUri'          => null,
            'requestUri'     => null,
            'body'           => null,
            'contentType'    => null,
            'headers'        => [],
            'headerMap'      => [],
            'expires'        => 0,
            'connection'     => null,
            'transport'      => 'UDP',
        ], $data);
    }

    /** 事件类型常量（EXOSIP_*） */
    public function getType() : int
    {
        return (int)$this->data['type'];
    }

    /** 响应状态码；请求事件为 0 */
    public function getCode() : int
    {
        return (int)$this->data['code'];
    }

    /** eXosip call_id（int 句柄） */
    public function getCallId() : int
    {
        return (int)$this->data['callId'];
    }

    /** eXosip dialog_id（int 句柄） */
    public function getDialogId() : int
    {
        return (int)$this->data['dialogId'];
    }

    /** eXosip subscription_id（int 句柄） */
    public function getSubscriptionId() : int
    {
        return (int)$this->data['subscriptionId'];
    }

    /** SIP 方法（REGISTER/MESSAGE/...） */
    public function getMethod() : ?string
    {
        return $this->data['method'];
    }

    public function getFromUri() : ?string
    {
        return $this->data['fromUri'];
    }

    public function getToUri() : ?string
    {
        return $this->data['toUri'];
    }

    public function getRequestUri() : ?string
    {
        return $this->data['requestUri'];
    }

    public function getBody() : ?string
    {
        return $this->data['body'];
    }

    public function getContentType() : ?string
    {
        return $this->data['contentType'];
    }

    /** 事务 ID（入站请求事件有效，用于 sendResponse/sendCallAnswer/sendNotifyResponse） */
    public function getTid() : int
    {
        return (int)$this->data['tid'];
    }

    /** Expires 头（int）；REGISTER Expires=0 表示注销 */
    public function getExpires() : int
    {
        $expires = (int)$this->data['expires'];
        if ($expires === 0) {
            // Contact 头中的 expires 参数兜底
            $contact = $this->getHeader('Contact');
            if ($contact && preg_match('/expires=(\d+)/i', $contact, $m)) {
                return (int)$m[1];
            }
        }
        return $expires;
    }

    /** 实际连接（源地址）：['ip' =>, 'port' =>] */
    public function getConnection() : ?array
    {
        return $this->data['connection'];
    }

    /** 大小写不敏感取头 */
    public function getHeader(string $name) : ?string
    {
        $lower = strtolower($name);
        if (array_key_exists($lower, $this->data['headerMap'] ?? [])) {
            return $this->data['headerMap'][$lower];
        }
        foreach (($this->data['headers'] ?? []) as $h) {
            if (strcasecmp($h['name'], $name) === 0) {
                return $h['value'];
            }
        }
        return null;
    }

    /**
     * 解析当前消息 SDP（Content-Type 为 application/sdp 时）
     * 返回 osip2 风格数组，含 GB28181 y= 扩展（gb28181.ssrc）
     */
    public function getSdp() : ?array
    {
        if ($this->sdpParsed) {
            return $this->sdpCache;
        }
        $this->sdpParsed = true;
        $body = $this->getBody();
        if ($body === null || trim($body) === '') {
            return null;
        }
        $ct = $this->getContentType() ?? '';
        if (stripos($ct, 'sdp') === false) {
            // 部分设备缺 Content-Type，按 v= 行兜底
            if (strncmp(ltrim($body), 'v=', 2) !== 0) {
                return null;
            }
        }
        $this->sdpCache = SipCodec::parseSdp($body);
        return $this->sdpCache;
    }

    /** 轻量会话句柄：业务层当前未使用，保持 null 兼容 */
    public function getSession() : ?SipSession
    {
        return null;
    }
}

/**
 * 占位会话类（SipEvent::getSession() 返回类型兼容，永不实例化）
 */
class SipSession
{
    public function getCallId() : int
    {
        return 0;
    }

    public function getRawBody() : ?string
    {
        return null;
    }

    public function close() : bool
    {
        return false;
    }
}
