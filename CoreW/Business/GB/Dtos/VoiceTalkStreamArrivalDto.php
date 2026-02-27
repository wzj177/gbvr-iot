<?php

namespace CoreW\Business\GB\Dtos;

/**
 * 语音对讲流到达 DTO
 * 用于封装 handleStreamArrival 方法的参数
 *
 * 包含 ZLM startSendRtpPassive 所需的 RTP 参数，
 * 由上层（VoiceTalkServiceImpl）根据 mode 设置，避免在 Gb28181Service 中硬编码。
 */
class VoiceTalkStreamArrivalDto
{
    private string $app;
    private string $stream;
    private string $mediaServerId;
    private string $ssrc;
    private ?int $rtpPort;
    private ?string $receiveStreamId;

    // RTP 推流参数
    private int $pt = 8;              // Payload Type: 8=PCMA (语音对讲默认)
    private bool $usePs = false;      // 是否使用 PS 封装 (语音对讲不需要)
    private bool $onlyAudio = true;   // 是否仅音频 (语音对讲默认 true)
    private bool $isTcp = true;       // 是否 TCP 模式 (默认 TCP 被动)

    private function __construct(
        string $app,
        string $stream,
        string $mediaServerId,
        string $ssrc,
        ?int $rtpPort = null,
        ?string $receiveStreamId = null
    ) {
        $this->app = $app;
        $this->stream = $stream;
        $this->mediaServerId = $mediaServerId;
        $this->ssrc = $ssrc;
        $this->rtpPort = $rtpPort;
        $this->receiveStreamId = $receiveStreamId;
    }

    public static function create(
        string $app,
        string $stream,
        string $mediaServerId,
        string $ssrc,
        ?int $rtpPort = null
    ): self {
        return new self($app, $stream, $mediaServerId, $ssrc, $rtpPort);
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

    public function getSsrc(): string
    {
        return $this->ssrc;
    }

    public function getRtpPort(): ?int
    {
        return $this->rtpPort;
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

    // RTP 参数 getters/setters

    public function getPt(): int
    {
        return $this->pt;
    }

    public function setPt(int $pt): self
    {
        $this->pt = $pt;
        return $this;
    }

    public function isUsePs(): bool
    {
        return $this->usePs;
    }

    public function setUsePs(bool $usePs): self
    {
        $this->usePs = $usePs;
        return $this;
    }

    public function isOnlyAudio(): bool
    {
        return $this->onlyAudio;
    }

    public function setOnlyAudio(bool $onlyAudio): self
    {
        $this->onlyAudio = $onlyAudio;
        return $this;
    }

    public function isTcp(): bool
    {
        return $this->isTcp;
    }

    public function setIsTcp(bool $isTcp): self
    {
        $this->isTcp = $isTcp;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'app' => $this->app,
            'stream' => $this->stream,
            'mediaServerId' => $this->mediaServerId,
            'ssrc' => $this->ssrc,
            'rtpPort' => $this->rtpPort,
            'receiveStreamId' => $this->receiveStreamId,
            'pt' => $this->pt,
            'usePs' => $this->usePs,
            'onlyAudio' => $this->onlyAudio,
        ];
    }
}
