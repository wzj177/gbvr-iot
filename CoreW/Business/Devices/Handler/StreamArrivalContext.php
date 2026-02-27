<?php

namespace CoreW\Business\Devices\Handler;

/**
 * 流到达处理上下文
 *
 * 封装 handleStreamArrival 过程中的公共数据，
 * 在 Handler 之间传递，避免方法签名过长。
 */
class StreamArrivalContext
{
    private string $app;
    private string $stream;
    private string $mediaServerId;

    /**
     * 从 DAO 查出的 voice_session 记录（已通过 CAS 更新为 STREAM_ARRIVED）
     */
    private array $session;

    /**
     * 分配的 SSRC
     */
    private ?string $ssrc = null;

    /**
     * 生成的设备侧收流 streamId（用于 ZLM startSendRtpPassive 的 dst_url）
     */
    private ?string $receiveStreamId = null;

    /**
     * 媒体服务器配置
     */
    private ?array $mediaServer = null;

    public function __construct(string $app, string $stream, string $mediaServerId, array $session)
    {
        $this->app = $app;
        $this->stream = $stream;
        $this->mediaServerId = $mediaServerId;
        $this->session = $session;
    }

    public function getApp(): string
    {
        return $this->app;
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public function getMediaServerId(): string
    {
        return $this->mediaServerId;
    }

    public function getSession(): array
    {
        return $this->session;
    }

    public function setSession(array $session): self
    {
        $this->session = $session;
        return $this;
    }

    /**
     * 更新 session 中的某些字段（合并到现有 session 数组中）
     */
    public function mergeSession(array $fields): self
    {
        $this->session = array_merge($this->session, $fields);
        return $this;
    }

    public function getSessionId(): string
    {
        return $this->session['session_id'];
    }

    public function getDeviceId(): string
    {
        return $this->session['device_id'];
    }

    public function getChannelId(): string
    {
        return $this->session['channel_id'];
    }

    public function getMode(): string
    {
        return $this->session['mode'] ?? 'talk';
    }

    public function getSsrc(): ?string
    {
        return $this->ssrc;
    }

    public function setSsrc(?string $ssrc): self
    {
        $this->ssrc = $ssrc;
        return $this;
    }

    public function getReceiveStreamId(): ?string
    {
        return $this->receiveStreamId;
    }

    public function setReceiveStreamId(?string $receiveStreamId): self
    {
        $this->receiveStreamId = $receiveStreamId;
        return $this;
    }

    public function getMediaServer(): ?array
    {
        return $this->mediaServer;
    }

    public function setMediaServer(?array $mediaServer): self
    {
        $this->mediaServer = $mediaServer;
        return $this;
    }
}
