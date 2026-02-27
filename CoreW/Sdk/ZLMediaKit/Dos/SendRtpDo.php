<?php

namespace CoreW\Sdk\ZLMediaKit\Dos;

/**
 * SendRtp 数据对象
 * 用于封装 startSendRtp 相关的参数
 */
class SendRtpDo
{
    private string $vhost = '__defaultVhost__';
    private string $app;
    private string $stream;
    private string $ssrc;
    private ?string $dst_url = null;
    private ?string $dst_port = null;
    private bool $is_udp = false;
    private ?int $src_port = null;
    private ?int $pt = null;
    private bool $use_ps = true;
    private bool $only_audio = false;
    private bool $rtcp = false;
    private ?int $close_delay_ms = null;
    private ?string $recv_stream_id = null;

    // 被动模式专用
    private bool $is_tcp = true;

    /**
     * 创建主动推流配置
     */
    public static function createActive(
        string $app,
        string $stream,
        string $ssrc,
        string $dst_url,
        string $dst_port,
        bool $is_udp = false
    ): self {
        $do = new self();
        $do->app = $app;
        $do->stream = $stream;
        $do->ssrc = $ssrc;
        $do->dst_url = $dst_url;
        $do->dst_port = $dst_port;
        $do->is_udp = $is_udp;
        return $do;
    }

    /**
     * 创建被动推流配置（TCP监听）
     */
    public static function createPassive(
        string $app,
        string $stream,
        string $ssrc,
        bool $is_tcp = true
    ): self {
        $do = new self();
        $do->app = $app;
        $do->stream = $stream;
        $do->ssrc = $ssrc;
        $do->is_tcp = $is_tcp;
        return $do;
    }

    /**
     * 创建语音对讲配置
     */
    public static function createTalk(
        string $app,
        string $stream,
        string $ssrc
    ): self {
        $do = new self();
        $do->app = $app;
        $do->stream = $stream;
        $do->ssrc = $ssrc;
        return $do;
    }

    // Getters
    public function getVhost(): string
    {
        return $this->vhost;
    }

    public function getApp(): string
    {
        return $this->app;
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public function getSsrc(): string
    {
        return $this->ssrc;
    }

    public function getDstUrl(): ?string
    {
        return $this->dst_url;
    }

    public function getDstPort(): ?string
    {
        return $this->dst_port;
    }

    public function isUdp(): bool
    {
        return $this->is_udp;
    }

    public function getSrcPort(): ?int
    {
        return $this->src_port;
    }

    public function getPt(): ?int
    {
        return $this->pt;
    }

    public function isUsePs(): bool
    {
        return $this->use_ps;
    }

    public function isOnlyAudio(): bool
    {
        return $this->only_audio;
    }

    public function isRtcp(): bool
    {
        return $this->rtcp;
    }

    public function getCloseDelayMs(): ?int
    {
        return $this->close_delay_ms;
    }

    public function getRecvStreamId(): ?string
    {
        return $this->recv_stream_id;
    }

    public function isTcp(): bool
    {
        return $this->is_tcp;
    }

    // Setters (支持链式调用)
    public function setVhost(string $vhost): self
    {
        $this->vhost = $vhost;
        return $this;
    }

    public function setApp(string $app): self
    {
        $this->app = $app;
        return $this;
    }

    public function setStream(string $stream): self
    {
        $this->stream = $stream;
        return $this;
    }

    public function setSsrc(string $ssrc): self
    {
        $this->ssrc = $ssrc;
        return $this;
    }

    public function setDstUrl(?string $dst_url): self
    {
        $this->dst_url = $dst_url;
        return $this;
    }

    public function setDstPort(?string $dst_port): self
    {
        $this->dst_port = $dst_port;
        return $this;
    }

    public function setIsUdp(bool $is_udp): self
    {
        $this->is_udp = $is_udp;
        return $this;
    }

    public function setSrcPort(?int $src_port): self
    {
        $this->src_port = $src_port;
        return $this;
    }

    public function setPt(?int $pt): self
    {
        $this->pt = $pt;
        return $this;
    }

    public function setUsePs(bool $use_ps): self
    {
        $this->use_ps = $use_ps;
        return $this;
    }

    public function setOnlyAudio(bool $only_audio): self
    {
        $this->only_audio = $only_audio;
        return $this;
    }

    public function setRtcp(bool $rtcp): self
    {
        $this->rtcp = $rtcp;
        return $this;
    }

    public function setCloseDelayMs(?int $close_delay_ms): self
    {
        $this->close_delay_ms = $close_delay_ms;
        return $this;
    }

    public function setRecvStreamId(?string $recv_stream_id): self
    {
        $this->recv_stream_id = $recv_stream_id;
        return $this;
    }

    public function setIsTcp(bool $is_tcp): self
    {
        $this->is_tcp = $is_tcp;
        return $this;
    }

    /**
     * 转换为数组（用于调试）
     */
    public function toArray(): array
    {
        return [
            'vhost' => $this->vhost,
            'app' => $this->app,
            'stream' => $this->stream,
            'ssrc' => $this->ssrc,
            'dst_url' => $this->dst_url,
            'dst_port' => $this->dst_port,
            'is_udp' => $this->is_udp,
            'src_port' => $this->src_port,
            'pt' => $this->pt,
            'use_ps' => $this->use_ps,
            'only_audio' => $this->only_audio,
            'rtcp' => $this->rtcp,
            'close_delay_ms' => $this->close_delay_ms,
            'recv_stream_id' => $this->recv_stream_id,
            'is_tcp' => $this->is_tcp,
        ];
    }
}
