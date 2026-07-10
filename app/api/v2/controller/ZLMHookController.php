<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use CoreW\Business\Devices\Enums\MediaServerStatus;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use CoreW\Business\StreamProxy\Service\StreamProxyService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\User\Service\UserService;
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
    public function onFlowReport(Request $request) : \support\Response
    {
        // TODO: 统计流量数据，可用于计费或流量分析
        // - 记录播放器/推流器的流量使用情况
        // - 统计用户观看时长
        // - 生成流量报告
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_FLOW_REPORT, '流量报告', $request->post());

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
    public function onHttpAccess(Request $request) : \support\Response
    {
        // TODO: HTTP 文件访问鉴权
        // - 验证用户是否有权限访问该文件
        // - 支持 token 验证
        // - 支持防盗链检查
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_HTTP_ACCESS, 'HTTP访问鉴权', $request->post());

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
    public function onPlay(Request $request) : \support\Response
    {
        // TODO: 播放鉴权和统计
        // - 验证用户是否有权限播放该流
        // - 统计播放次数
        // - 记录观看日志
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_PLAY, '播放事件', $request->post());

        return json(['code' => 0]);
    }

    /**
     * 推流事件
     */
    public function onPublish(Request $request) : \support\Response
    {
        $streamId = $request->post('stream') ?? $request->post('stream_id');
        $app = $request->post('app', 'rtp');
        $paramsStr = $request->post('params', null);
        $mediaServerId = $request->mediaServer['server_id'] ?? '';

//        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_PUBLISH, '推流事件', $request->post());

        $result = [
            'code'         => 0,
            'msg'          => '',
            'enable_audio' => false,
            'enable_mp4'   => false, // TODO:对于云录制的流需开启此功能
        ];

        try {
            $result = match (true) {
                $app === 'rtp' => $this->handleRtpPublish($streamId, $mediaServerId, $result),
                in_array($app, ['talk', 'broadcast']) => $this->handleVoicePublish($app, $streamId, $paramsStr, $result),
                $app === 'push' => $this->handlePushPublish($streamId, $mediaServerId, $result),
                default => $result,
            };
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_PUBLISH, '推流鉴权异常', [
                'app' => $app, 'stream' => $streamId, 'error' => $e->getMessage(),
            ]);
        }

        return json($result);
    }

    /**
     * RTP 推流处理：按流会话类型控制 enable_mp4/enable_audio
     */
    private function handleRtpPublish(string $streamId, string $mediaServerId, array $result) : array
    {
        $session = $this->getDeviceService()->getSessionByStreamId($streamId);
        if (!$session) {
            return $result;
        }

        $type = $session['type'] ?? '';

        if ($type === 'download') {
            $result['enable_mp4'] = true;
            if (str_contains($streamId, 'download_')) {
                $this->getRecordTaskService()->updateRecordStartTimeByStreamId($streamId, $mediaServerId, time());
            }
        }

        // TODO: enable_audio 根据通道 has_audio 字段判断（目前通道表无此字段）
        $result['enable_audio'] = true;

        return $result;
    }

    /**
     * 语音对讲/广播推流鉴权：验证 sign token，开启音频
     */
    private function handleVoicePublish(string $app, string $streamId, ?string $paramsStr, array $result) : array
    {
        $params = [];
        if ($paramsStr) {
            parse_str($paramsStr, $params);
        }

        if (!empty($params['sign'])) {
            $signInfo = explode(':', str_replace('%3A', ':', $params['sign']));
            $valid = false;
            if (count($signInfo) >= 2) {
                if ($signInfo[0] === 'adm') {
                    // 管理端申请的
                    $existUser = $this->getUserService()->getUserByUUID($signInfo[1]);
                    $valid = !empty($existUser);
                } else if ($signInfo[0] === 'vip') {
                    // 会员端申请的
                } else if ($signInfo[0] === 'share') {
                    // 分享的，需要验证过期时间
                }

                if (!$valid) {
                    $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_PUBLISH, '推流鉴权失败', [
                        'app' => $app, 'stream' => $streamId, 'params' => $params,
                    ]);
                    return ['code' => -1, 'msg' => 'token验证失败'];
                }
            }
        }

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_PUBLISH_AUTH, '语音推流鉴权通过', [
            'app' => $app, 'stream' => $streamId,
        ]);

        $result['enable_audio'] = true;
        $result['enable_mp4'] = false;
        return $result;
    }

    /**
     * OBS/FFmpeg push 推流接入处理
     * 同步后台 StreamProxy(push 类型)状态为 online
     */
    private function handlePushPublish(string $streamId, string $mediaServerId, array $result) : array
    {
        try {
            $this->getStreamProxyService()->syncPushStreamStatus('push', $streamId, true, $mediaServerId);
        } catch (\Throwable $e) {
            // 同步失败不影响 ZLM 接受推流
        }
        return $result;
    }

    /**
     * 处理推流结束（on_unpublish Hook）
     */
    public function onUnpublish(Request $request) : \support\Response
    {
        $streamId = $request->post('stream') ?? $request->post('stream_id');
        $app = $request->post('app', 'rtp');
        $vhost = $request->post('vhost', '__defaultVhost__');

        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_UNPUBLISH, '推流结束', $request->post());

        // 处理语音对讲推流结束
        if (in_array($app, ['talk', 'broadcast'])) {
            try {
                $mediaServerId = $request->mediaServer['server_id'] ?? '';
                $this->getVoiceTalkService()->handleStreamDeparture($app, $streamId, $mediaServerId);
            } catch (\Throwable $e) {
                $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_UNPUBLISH_FAILED, '语音对讲推流结束处理失败', [
                    'app'    => $app,
                    'stream' => $streamId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return json(['code' => 0]);
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
    public function onRecordMp4(Request $request) : \support\Response
    {
        $hookData = $request->post();

        // 记录原始数据
        $this->getLogService()->info(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, 'MP4录像完成', $hookData);

        try {
            $this->getRecordFileService()->createFromHook($hookData, $request->mediaServer['server_id']);
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK_FAILED, '创建录像文件失败', [
                'error'  => $e->getMessage(),
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
    public function onRecordTs(Request $request) : \support\Response
    {
        // TODO: HLS/TS 录像文件处理
        // - 保存录像文件信息到数据库
        // - 触发录像完成回调
        $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_ON_RECORD_TS, 'TS录像完成', $request->post());

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
    public function onRtspAuth(Request $request) : \support\Response
    {
        // TODO: RTSP 认证
        // - 验证用户名密码
        // - 支持 token 验证
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_RTSP_AUTH, 'RTSP认证', $request->post());

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
    public function onRtspRealm(Request $request) : \support\Response
    {
        // TODO: 返回 RTSP realm
        // - 可根据不同的 vhost/app/stream 返回不同的 realm
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_RTSP_REALM, 'RTSP Realm', $request->post());

        return json([
            'code'  => 0,
            'realm' => config('app.name', 'GBVR-IoT'),
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
    public function onServerStarted(Request $request) : \support\Response
    {
        // TODO: 服务器启动处理
        // - 更新媒体服务器状态为在线
        // - 保存服务器配置信息
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_SERVER_STARTED, '服务器启动', $request->post());

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
    public function onShellLogin(Request $request) : \support\Response
    {
        // TODO: Shell 登录鉴权
        // - 验证用户名密码
        // - 限制登录 IP
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_SHELL_LOGIN, 'Shell登录', $request->post());

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
    public function onStreamChanged(Request $request) : \support\Response
    {
        //{ "app": "talk", "stream": "talk_1920268492", "register": 0, "schema": "rtmp" }
        $schema = $request->post('schema', '');
        $stream = $request->post('stream', '');
        $app = $request->post('app', '');
        $vhost = $request->post('vhost', '__defaultVhost__');
        $register = $request->post('regist', 0); // 1=注册，0=注销
        $mediaServerId = $request->mediaServer['server_id'] ?? '';
        //        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_STREAM_CHANGED, $stream  . '流' . ($register ? '上线' : '下线'), $request->post());
        //        if ($register) {
        //            // 流注册上线
        //            // TODO: 更新流状态、通知前端
        //        } else {
        //            // 流注销下线
        //            // TODO: 清理会话、更新流状态
        //        }
        //  处理语音对讲/喊话流
        if (in_array($app, ['talk', 'broadcast']) && strtolower($schema) === 'rtsp') {
            try {
                if ($register) {
                    $this->getVoiceTalkService()->handleStreamArrival($app, $stream, $mediaServerId);
                } else {
                    $this->getVoiceTalkService()->handleStreamDeparture($app, $stream, $mediaServerId);
                }
            } catch (\Throwable $e) {
                $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_STREAM_CHANGED_FAILED, '语音流变化处理失败', [
                    'app'      => $app,
                    'stream'   => $stream,
                    'register' => $register,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);
            }
        }

        // push 推流下线（OBS 断开）→ 更新后台状态为 offline
        // 上线在 onPublish 里处理，这里只处理注销(register=0)
        if ($app === 'push' && !$register) {
            try {
                $this->getStreamProxyService()->syncPushStreamStatus('push', $stream, false, $mediaServerId);
            } catch (\Throwable $e) {
                // 同步失败不影响 hook 返回
            }
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
    public function onStreamNoneReader(Request $request) : \support\Response
    {
        $streamId = $request->post('stream');
        $app = $request->post('app', 'rtp');  //  新增：获取 app 参数

        //  新增：处理语音对讲流
        if (in_array($app, ['talk', 'broadcast'])) {
            return json(['code' => 0, 'close' => false]);
        }

        // 原有逻辑：清理普通流会话
        try {
            $sessions = $this->getDeviceService()->searchSessions([
                'media_server_id' => $request->mediaServer['server_id'],
                'stream_id'       => $streamId,
                'viewer_count_GE' => 1,
            ], [], 0, PHP_INT_MAX, ['id']);

            $ids = array_column($sessions, 'id');
            if (!empty($ids)) {
                $this->getDeviceService()->batchDeleteSessions($ids);
            }
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_STREAM_NONE_READER, '清理无人观看的流会话失败', [
                'error' => $e->getMessage(),
            ]);
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
    public function onStreamNotFound(Request $request) : \support\Response
    {
        // TODO: 流未找到处理
        // - 记录 404 错误日志
        // - 尝试拉流代理
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_STREAM_NOT_FOUND, '播放不存在的流，设备可能停止推流', $request->post());

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
    public function onStreamNotFoundFfmpeg(Request $request) : \support\Response
    {
        // TODO: FFmpeg 拉流失败处理
        // - 记录错误日志
        // - 清理失败的拉流任务
        $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_STREAM_NOT_FOUND_FFMPEG, 'FFmpeg拉流失败', $request->post());

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
    public function onRtpServerTimeout(Request $request) : \support\Response
    {
        // TODO: RTP 超时处理
        // - 清理超时的 RTP 会话
        // - 更新通道状态
        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_ON_RTP_SERVER_TIMEOUT, 'RTP超时', $request->post());
        if ($request->post('stream_id')) {
            $this->getDeviceService()->deleteSessionByStreamIdAndMediaServerId($request->post('stream_id'), $request->mediaServer['server_id']);
        }

        return json(['code' => 0]);
    }

    public function onServerKeepalive(Request $request)
    {
        try {
            $this->getMediaServerService()->updateMediaServer($request->mediaServer['id'], [
                'last_heartbeat_time' => date('Y-m-d H:i:s'),

                'status' => MediaServerStatus::RUNNING->value,
            ]);
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_ON_SERVER_KEEPALIVE, '更新媒体服务器信息失败', [
                'error' => $e->getMessage(),
            ]);
        }
        return json(['code' => 0]);
    }


    protected function getUserService() : UserService
    {
        return $this->getBiz()->service('User:UserService');
    }

    protected function getDeviceService() : DeviceService
    {
        return $this->getBiz()->service('Devices:DeviceService');
    }

    protected function getRecordTaskService() : RecordTaskService
    {
        return $this->getBiz()->service('Record:RecordTaskService');
    }

    protected function getRecordFileService() : RecordFileService
    {
        return $this->getBiz()->service('RecordFile:RecordFileService');
    }

    protected function getVoiceTalkService() : VoiceTalkService
    {
        return $this->createService('Devices:VoiceTalkService');
    }

    protected function getMediaServerService() : MediaServerService
    {
        return $this->getBiz()->service('MediaServer:MediaServerService');
    }

    protected function getStreamProxyService() : StreamProxyService
    {
        return $this->getBiz()->service('StreamProxy:StreamProxyService');
    }
}
