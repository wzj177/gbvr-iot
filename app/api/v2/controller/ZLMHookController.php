<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use support\Log;
use support\Request;

/**
 * ZLMediaKit Hook 控制器
 *
 * 处理 ZLM 触发的各种事件回调
 */
class ZLMHookController extends BaseController
{
    /**
     * 流量报告事件
     *
     * 播放器或推流器断开时并且耗用流量超过特定阈值时会触发此事件
     * 阈值通过配置文件 general.flowThreshold 配置
     *
     * POST 参数示例：
     * {
     *   "mediaServerId": "your_server_id",
     *   "app": "live",
     *   "duration": 6,
     *   "params": "token=1677193e-1244-49f2-8868-13b3fcc31b17",
     *   "player": false,
     *   "schema": "rtmp",
     *   "protocol": "rtmp",
     *   "stream": "obs",
     *   "totalBytes": 1508161,
     *   "vhost": "__defaultVhost__",
     *   "ip": "192.168.0.21",
     *   "port": 55345,
     *   "id": "140259799100960"
     * }
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onFlowReport(Request $request): \support\Response
    {
        // TODO: 统计流量数据，可用于计费或流量分析
        // - 记录播放器/推流器的流量使用情况
        // - 统计用户观看时长
        // - 生成流量报告
        Log::channel('zlm_hook')->info('on_flow_report', $request->post());

        return json(['code' => 0]);
    }

    /**
     * HTTP 访问鉴权事件
     *
     * 在访问 http 文件时触发，可用于鉴权
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onHttpAccess(Request $request): \support\Response
    {
        // TODO: HTTP 文件访问鉴权
        // - 验证用户是否有权限访问该文件
        // - 支持 token 验证
        // - 支持防盗链检查
        Log::channel('zlm_hook')->info('on_http_access', $request->post());

        // 返回 code=0 表示允许访问，code=-1 表示拒绝
        return json(['code' => 0, 'path' => $request->post('path', '')]);
    }

    /**
     * 播放事件
     *
     * 播放器播放流时触发
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onPlay(Request $request): \support\Response
    {
        // TODO: 播放鉴权和统计
        // - 验证用户是否有权限播放该流
        // - 统计播放次数
        // - 记录观看日志
        Log::channel('zlm_hook')->info('on_play', $request->post());

        return json(['code' => 0]);
    }

    /**
     * 推流事件
     *
     * 流推送到服务器时触发，可用于鉴权和修改流参数
     *
     * Hook 作用：更新 record_start_time（仅作为加速器）
     * 不改变 status，业务逻辑由 Scheduler 统一处理
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onPublish(Request $request): \support\Response
    {
        $streamId = $request->post('stream') ?? $request->post('stream_id');
        $vhost = $request->post('vhost', '__defaultVhost__');
        $app = $request->post('app', 'rtp');
        $schema = $request->post('schema', 'rtsp');

        Log::channel('zlm_hook')->info('on_publish', $request->post());

        // Hook 作用：仅更新 record_start_time（如果为0）
        // 这里的更新只是"提前填充"，实际业务判断由 Scheduler 基于 last_rtp_time 统一处理
        if ($streamId) {
            if (str_contains($streamId, 'download_')) {
                $mediaServerId = $request->mediaServer['server_id'] ?? '';
                try {
                    $this->getRecordTaskService()->updateRecordStartTimeByStreamId($streamId, $mediaServerId, time());
                } catch (\Throwable $e) {
                    Log::channel('zlm_hook')->error('on_publish update record_start_time failed', [
                        'stream_id' => $streamId,
                        'media_server_id' => $mediaServerId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 可返回以下参数动态修改流的配置：
        return json([
            'code' => 0,
            'enable_hls' => 1,
            'enable_mp4' => 0,
            'enable_rtsp' => 1,
            'enable_rtmp' => 1,
            'enable_ts' => 1,
        ]);
    }

    /**
     * MP4 录像完成事件
     *
     * MP4 录像切片完成时触发
     *
     * Hook 作用：保存录像文件信息到数据库
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onRecordMp4(Request $request): \support\Response
    {
        $hookData = $request->post();

        // 记录原始数据
        Log::channel('zlm_hook')->info('on_record_mp4', $hookData);

        try {
            $this->getRecordFileService()->createFromHook($hookData, $request->mediaServer['server_id']);
        } catch (\Throwable $e) {
            Log::channel('zlm_hook')->error('on_record_mp4 create failed', [
                'error' => $e->getMessage(),
                'stream' => $hookData['stream'] ?? '',
            ]);
        }

        return json(['code' => 0]);
    }

    /**
     * HLS/TS 录像完成事件
     *
     * HLS 或 TS 录像切片完成时触发
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onRecordTs(Request $request): \support\Response
    {
        // TODO: HLS/TS 录像文件处理
        // - 保存录像文件信息到数据库
        // - 触发录像完成回调
        Log::channel('zlm_hook')->info('on_record_ts', $request->post());

        return json(['code' => 0]);
    }

    /**
     * RTSP 认证事件
     *
     * RTSP 播放或推流时进行鉴权
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onRtspAuth(Request $request): \support\Response
    {
        // TODO: RTSP 认证
        // - 验证用户名密码
        // - 支持 token 验证
        Log::channel('zlm_hook')->info('on_rtsp_auth', $request->post());

        // 返回 code=0 表示认证成功，code=-1 表示失败
        return json(['code' => 0]);
    }

    /**
     * RTSP Realm 事件
     *
     * 获取 RTSP 认证 realm
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onRtspRealm(Request $request): \support\Response
    {
        // TODO: 返回 RTSP realm
        // - 可根据不同的 vhost/app/stream 返回不同的 realm
        Log::channel('zlm_hook')->info('on_rtsp_realm', $request->post());

        return json([
            'code' => 0,
            'realm' => config('app.name', 'GBVR-IoT')
        ]);
    }

    /**
     * 服务器启动事件
     *
     * ZLM 服务器启动时触发（通常只触发一次）
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onServerStarted(Request $request): \support\Response
    {
        // TODO: 服务器启动处理
        // - 更新媒体服务器状态为在线
        // - 保存服务器配置信息
        Log::channel('zlm_hook')->info('on_server_started', $request->post());

        return json(['code' => 0]);
    }

    /**
     * Shell 登录事件
     *
     * ZLM 控制台登录鉴权
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onShellLogin(Request $request): \support\Response
    {
        // TODO: Shell 登录鉴权
        // - 验证用户名密码
        // - 限制登录 IP
        Log::channel('zlm_hook')->info('on_shell_login', $request->post());

        // 返回 code=0 表示认证成功，code=-1 表示失败
        return json(['code' => -1, 'msg' => 'Shell login disabled']);
    }

    /**
     * 流注册/注销事件
     *
     * 流上线（注册）或下线（注销）时触发
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onStreamChanged(Request $request): \support\Response
    {
        $schema = $request->post('schema', '');
        $stream = $request->post('stream', '');
        $app = $request->post('app', '');
        $vhost = $request->post('vhost', '__defaultVhost__');
        $register = $request->post('register', 0); // 1=注册，0=注销

        try {
            if ($register) {
                // 流注册上线
                // TODO: 更新流状态、通知前端
            } else {
                // 流注销下线
                // TODO: 清理会话、更新流状态
            }
        } catch (\Throwable $e) {
            Log::channel('zlm_hook')->error('on_stream_changed error: ' . $e->getMessage());
        }

        return json(['code' => 0]);
    }

    /**
     * 流无人观看事件
     *
     * 流无人观看时触发，可选择是否关闭该流
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onStreamNoneReader(Request $request): \support\Response
    {
        $streamId = $request->post('stream');

        try {
            $sessions = $this->getDeviceService()->searchSessions([
                'media_server_id' => $request->mediaServer['server_id'],
                'stream_id' => $streamId,
                'viewer_count_GE' => 1
            ], [], 0, PHP_INT_MAX, ['id']);

            $ids = array_column($sessions, 'id');
            if (!empty($ids)) {
                $this->getDeviceService()->batchDeleteSessions($ids);
            }
        } catch (\Throwable $e) {
            Log::channel('zlm_hook')->error("onStreamNoneReader clean session error: " . $e->getMessage());
        }

        // 返回 close=true 关闭流，close=false 保持流
        return json(['code' => 0, 'close' => true]);
    }

    /**
     * 流未找到事件
     *
     * 播放不存在的流时触发
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onStreamNotFound(Request $request): \support\Response
    {
        // TODO: 流未找到处理
        // - 记录 404 错误日志
        // - 尝试拉流代理
        Log::channel('zlm_hook')->info('on_stream_not_found', $request->post());

        return json(['code' => 0]);
    }

    /**
     * FFmpeg 拉流未找到事件
     *
     * FFmpeg 拉流失败时触发
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onStreamNotFoundFfmpeg(Request $request): \support\Response
    {
        // TODO: FFmpeg 拉流失败处理
        // - 记录错误日志
        // - 清理失败的拉流任务
        Log::channel('zlm_hook')->info('on_stream_not_found_ffmpeg', $request->post());

        return json(['code' => 0]);
    }

    /**
     * RTP 超时事件
     *
     * GB28181 RTP 推流超时时触发
     *
     * @param Request $request
     * @return \support\Response
     */
    public function onRtpServerTimeout(Request $request): \support\Response
    {
        // TODO: RTP 超时处理
        // - 清理超时的 RTP 会话
        // - 更新通道状态
        Log::channel('zlm_hook')->info('on_rtp_server_timeout', $request->post());

        return json(['code' => 0]);
    }

    /**
     * @return Gb28181Service
     */
    protected function getGB28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    protected function getDeviceService(): DeviceService
    {
        return $this->getBiz()->service('Devices:DeviceService');
    }

    protected function getRecordTaskService(): RecordTaskService
    {
        return $this->getBiz()->service('Record:RecordTaskService');
    }

    protected function getRecordFileService(): RecordFileService
    {
        return $this->getBiz()->service('RecordFile:RecordFileService');
    }
}
