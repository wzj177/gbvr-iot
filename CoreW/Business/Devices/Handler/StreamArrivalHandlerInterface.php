<?php

namespace CoreW\Business\Devices\Handler;

/**
 * 流到达处理器接口
 *
 * 当 ZLM on_publish 回调到达时，VoiceTalkServiceImpl 完成通用的会话查找
 * 和 CAS 状态转换（WAITING_STREAM -> STREAM_ARRIVED）后，
 * 将具体的信令发送流程委托给对应的 Handler 实现。
 *
 * 两种模式的流程差异：
 * - Talk:      互斥检查 -> 申请SSRC -> 开被动收流端口 -> 发SIP INVITE -> 启动超时定时器
 * - Broadcast: 冲突检查 -> 申请SSRC -> 发SIP MESSAGE通知 -> 启动等待设备INVITE定时器
 */
interface StreamArrivalHandlerInterface
{
    /**
     * 处理流到达后的信令发送流程
     *
     * @param StreamArrivalContext $context 流到达上下文（包含 session、mediaServer 等信息）
     * @return bool 处理是否成功
     */
    public function handle(StreamArrivalContext $context): bool;
}
