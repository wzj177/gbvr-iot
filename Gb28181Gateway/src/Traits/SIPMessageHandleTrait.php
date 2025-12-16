<?php

namespace Gb28181\GateWay\Traits;

trait SIPMessageHandleTrait
{
    /**
     * 处理 INVITE 的 200 OK 响应（含设备 SDP）
     *
     *  SDP 解析器核心应用场景
     *  集成 ZLMediaKit 完成视频流接收
     */
    protected function handleInviteResponse(\SipEvent $event): void
    {
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();

        $this->log("收到 INVITE 200 OK: Call-ID=$callId, Dialog-ID=$dialogId");

        // 调试: 检查 body 和 content_type
        $body = $event->getBody();
        $contentType = $event->getContentType();  // 使用专用方法,不是 getHeader()
        $this->log("DEBUG body=" . ($body ? substr($body, 0, 50) . '...' : 'NULL'), 'DEBUG');
        $this->log("DEBUG content_type=" . ($contentType ?: 'NULL'), 'DEBUG');

        //  使用原生 SDP 解析器
        $sdp = $event->getSdp();

        if ($sdp === null) {
            $this->log("200 OK 不含有效 SDP", 'WARNING');
            return;
        }

        $this->log("SDP 解析成功, sdp=" . serialize($sdp));

        // 提取设备媒体信息
        $deviceIp = $sdp['origin']['addr'] ?? null;
        $devicePort = isset($sdp['medias'][0]) ? $sdp['medias'][0]['port'] : null;
        $protocol = isset($sdp['medias'][0]) ? $sdp['medias'][0]['proto'] : 'RTP/AVP';
        $mediaType = isset($sdp['medias'][0]) ? $sdp['medias'][0]['media'] : 'unknown';

        //  提取 GB28181 SSRC（关键！）
        $ssrc = $sdp['gb28181']['ssrc'] ?? null;

        if (!$deviceIp || !$devicePort) {
            $this->log("SDP 缺少设备 IP 或端口", 'ERROR');
            return;
        }

        $this->log("设备媒体地址: {$deviceIp}:{$devicePort} (协议: {$protocol})");

        if ($ssrc) {
            $this->log("设备 SSRC: {$ssrc}");
        } else {
            $this->log("警告: SDP 中未找到 SSRC", 'WARNING');
        }

        // RFC 3261: UAC 必须在收到 200 OK 后发送 ACK
        if ($dialogId > 0) {
            $ackResult = $this->sipServer->sendAck($dialogId);
            if ($ackResult) {
                $this->log("✓ ACK 已发送 (Dialog-ID: {$dialogId})");
            } else {
                $this->log("✗ ACK 发送失败 (Dialog-ID: {$dialogId})", 'ERROR');
                return;
            }
        } else {
            $this->log("警告: Dialog-ID 无效,无法发送 ACK", 'WARNING');
            return;
        }

        //  关键步骤: 通知外部系统（API项目）媒体流已就绪
        // API项目会使用设备SSRC更新ZLM,并监控RTP流
        $this->postTask('media_ready', [
            'call_id' => $callId,
            'dialog_id' => $dialogId,
            'device_ip' => $deviceIp,
            'device_port' => $devicePort,
            'protocol' => $protocol,
            'media_type' => $mediaType,
            'device_ssrc' => $ssrc,  // 关键：设备实际使用的SSRC
            'sdp' => $sdp,
            'timestamp' => time(),
        ]);

        $this->log("✓ INVITE 200 OK 处理完成,设备应开始推流到 ZLM");
    }

    /**
     * 处理 MESSAGE 的 200 OK 响应（查询命令已被设备接收）
     *
     * 设备收到查询命令后:
     * 1. 立即返回 200 OK (表示命令已接收)
     * 2. 然后异步发送 MESSAGE 响应 (包含查询结果)
     */
    protected function handleMessageResponse(\SipEvent $event): void
    {
        $toUri = $event->getToUri();
        $deviceId = $this->extractDeviceId($toUri);

        if ($this->config['debug'] ?? false) {
            $this->log("MESSAGE 200 OK: 设备 {$deviceId} 已接收查询命令", 'DEBUG');
        }

        // 可选: 通知 API 命令已被设备接收
        // 实际的查询结果会通过 handleMessage 中的 handleCatalog/handleDeviceInfo 等处理
    }
}