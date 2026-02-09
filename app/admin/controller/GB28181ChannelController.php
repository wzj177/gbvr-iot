<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\DeviceChannelFilter;
use CoreW\Business\Devices\Enums\ChannelTypeEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Service\PlaybackRecordService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\SystemLog\LogEnum;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use support\Redis;
use support\Request;
use support\utils\ArrayToolkit;
use support\utils\Paginator;

/**
 * GB28181 通道管理控制器 - 管理后台
 */
class GB28181ChannelController extends BaseController
{
    /**
     * 获取设备通道列表
     */
    public function index(Request $request)
    {
        // 构建查询条件
        $conditions = [
            'channel_types' => [ChannelTypeEnum::CAMERA->value, ChannelTypeEnum::IPC->value]
        ];

        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        // device_id
        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }

        if ($request->get('channel_id')) {
            $conditions['channel_id'] = $request->get('channel_id');
        }

        if ($request->get('channel_type')) {
            $conditions['channel_type'] = $request->get('channel_type');
        }

        if ($request->get('keyword')) {
            $conditions['keywords'] = $request->get('keyword');
        }

        $total = $this->getDeviceService()->countChannels($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $channels = $this->getDeviceService()->searchChannels($conditions, ['status' => 'ASC', 'id' => 'DESC'], $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        // 关联查询媒体服务器信息
        $mediaServerIds = array_filter(array_unique(array_column($channels, 'media_server_id')));
        $mediaServers = !empty($mediaServerIds)
            ? $this->getMediaServerService()->findServersByServerIds($mediaServerIds)
            : [];
        $mediaServerMap = ArrayToolkit::index($mediaServers, 'server_id');

        // 为每个通道附加媒体服务器信息
        foreach ($channels as &$channel) {
            if (!empty($channel['media_server_id']) && isset($mediaServerMap[$channel['media_server_id']])) {
                $channel['media_server'] = $mediaServerMap[$channel['media_server_id']];
            } else {
                $channel['media_server'] = null;
            }
        }

        return $this->createSuccessJsonResponse([
            'list' => DeviceChannelFilter::publicList($channels),
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取通道详情
     */
    public function show(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        // 关联查询媒体服务器信息
        if (!empty($channel['media_server_id'])) {
            $mediaServer = $this->getMediaServerService()->getServer($channel['media_server_id']);
            if ($mediaServer) {
                $channel['media_server'] = $this->filterMediaServerInfo($mediaServer);
            }
            unset($channel['media_server_id']);
        }

        return $this->createSuccessJsonResponse($channel);
    }

    /**
     * 更新通道信息
     */
    public function update(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        $data = $request->post();
        $allowedFields = [
            'show_name',    // 显示名称
            'origin_code',  // 级联编号
            'custom_lat',   // 自填纬度
            'custom_lng',   // 自填经度
        ];

        // 过滤只允许更新的字段
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('没有可更新的字段', 400);
        }

        // 验证经纬度格式（如果提供）
        if (isset($updateData['custom_lat'])) {
            $lat = (float)$updateData['custom_lat'];
            if ($lat < -90 || $lat > 90) {
                return $this->createErrorJsonResponse('纬度必须在 -90 到 90 之间', 400);
            }
        }

        if (isset($updateData['custom_lng'])) {
            $lng = (float)$updateData['custom_lng'];
            if ($lng < -180 || $lng > 180) {
                return $this->createErrorJsonResponse('经度必须在 -180 到 180 之间', 400);
            }
        }

        try {
            $this->getDeviceService()->updateChannel($id, $updateData);

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, 'update_channel', '更新通道失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('更新通道失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 删除通道
     */
    public function destroy(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        try {
            $this->getDeviceService()->deleteChannel($id);

            return $this->createSuccessJsonResponse(null, '删除成功');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, 'delete_channel', '删除通道失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('删除通道失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 批量绑定媒体服务器
     */
    public function batchBindMedia(Request $request)
    {
        $ids = $request->post('ids', []);
        $mediaServerId = $request->post('server_id', 'default');

        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要绑定的通道');
        }

        if (empty($mediaServerId)) {
            return $this->createErrorJsonResponse('请选择媒体服务器');
        }

        // 验证媒体服务器是否存在
        $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($mediaServerId);
        if (!$mediaServer) {
            return $this->createErrorJsonResponse('媒体服务器不存在');
        }

        try {
            $affectedRows = $this->getDeviceService()->batchUpdateChannels($ids, [
                'media_server_id' => $mediaServerId
            ]);

            $this->getLogService()->info('gb28181', 'batch_bind_media_channel', "批量绑定媒体服务器到通道，成功: {$affectedRows}个", [
                'ids' => $ids,
                'mediaServerId' => $mediaServerId,
            ]);

            return $this->createSuccessJsonResponse([
                'successCount' => $affectedRows,
                'message' => "成功绑定 {$affectedRows} 个通道到媒体服务器",
            ]);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_BATCH_BIND_MEDIA, '批量绑定媒体服务器到通道失败', [
                'ids' => $ids,
                'mediaServerId' => $mediaServerId,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('批量绑定媒体服务器失败: ' . $e->getMessage(), 500);
        }
    }

    public function filterChannelTypes()
    {
        $items = [
            [
                'code' => ChannelTypeEnum::ALARM_INPUT->value,
                'name' => ChannelTypeEnum::ALARM_INPUT->label(),
            ],
            [
                'code' => ChannelTypeEnum::ALARM_OUTPUT->value,
                'name' => ChannelTypeEnum::ALARM_OUTPUT->label(),
            ],
            [
                'code' => ChannelTypeEnum::AUDIO_INPUT->value,
                'name' => ChannelTypeEnum::AUDIO_INPUT->label(),
            ],
            [
                'code' => ChannelTypeEnum::AUDIO_OUTPUT->value,
                'name' => ChannelTypeEnum::AUDIO_OUTPUT->label(),
            ],
            [
                'code' => ChannelTypeEnum::SIGNAL_SERVER->value,
                'name' => ChannelTypeEnum::SIGNAL_SERVER->label(),
            ],
            [
                'code' => ChannelTypeEnum::BUSINESS_GROUP->value,
                'name' => ChannelTypeEnum::BUSINESS_GROUP->label(),
            ],
            [
                'code' => ChannelTypeEnum::VIRTUAL_ORG->value,
                'name' => ChannelTypeEnum::VIRTUAL_ORG->label(),
            ]
        ];

        return $this->createSuccessJsonResponse($items);
    }

    /**
     * 通道类型选项（用于列表筛选）
     */
    public function channelTypeOptions()
    {
        $items = [];
        foreach (ChannelTypeEnum::cases() as $case) {
            $items[] = [
                'code' => $case->value,
                'name' => $case->label(),
            ];
        }

        return $this->createSuccessJsonResponse($items);
    }

    /**
     * 获取URL编码信息
     * 使用 ffprobe 获取视频流的编码信息
     */
    public function getUrlCodecInfo(Request $request)
    {
        $url = $request->post('url');

        if (empty($url)) {
            return $this->createErrorJsonResponse('缺少必要参数: url', 400);
        }

        // 验证URL格式
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->createErrorJsonResponse('URL格式不正确', 400);
        }

        $streamId = $request->post('stream_id', null);

        if ($streamId) {
            try {
                // TODO: 找到media-server ID，然后区分处理
                $schema = $this->getZLMediaKitSchemaFromUrl($url);

                $mediaInfo = $this->getGb28181Service()->queryStreamSchemaMediaInfo($streamId, $schema);

                return $this->createSuccessJsonResponse([
                    'way' => 'media-api',
                    'data' => $mediaInfo
                ]);
            } catch (\Exception $e) {};
        }

        try {
            $command = [
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                '-probesize', '32768',
                '-analyzeduration', '800000', // 0.8秒
                '-timeout', '5000000',
                '-user_agent', 'Mozilla/5.0 (compatible; FFprobe)',
                $url
            ];
            $ffprobe = $this->getFFProbe();
            $resultStr = $ffprobe->getFFProbeDriver()->command($command);

            $result = json_decode($resultStr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->createErrorJsonResponse('解析JSON异常: ' . json_last_error_msg(), 500);
            }

            // 提取视频和音频编码信息
            $codecInfo = $this->parseCodecInfo($result);

            return $this->createSuccessJsonResponse([
                'way' => 'ffprobe',
                'data' => $codecInfo
            ]);

        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, 'get_url_codec_info', '获取URL编码信息失败', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('获取编码信息异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 从 ZLMediaKit 风格的流媒体 URL 中解析出对应的逻辑 schema（媒体类型）
     *
     * 支持的返回值包括：
     * - 'rtsp'   : RTSP 流（含 rtsps）
     * - 'rtmp'   : RTMP 流（含 rtmps）
     * - 'flv'    : FLV 封装（http-flv / ws-flv）
     * - 'ts'     : MPEG-TS 封装（http-ts / ws-ts）
     * - 'fmp4'   : Fragmented MP4（http-fmp4 / ws-fmp4）
     * - 'hls_ts' : HLS 基于 TS 分片
     * - 'hls_fmp4': HLS 基于 fMP4 分片
     * - 'mp4'    : 普通 MP4 点播（仅 http/https + .mp4 路径，无 .live.* 后缀）
     * - null     : 无法识别
     *
     * @param string $url 完整的流媒体 URL
     * @return string|null
     */
    private function getZLMediaKitSchemaFromUrl(string $url): ?string
    {
        $url = trim($url);
        if (!$url) {
            return null;
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme']);
        $path   = $parsed['path'] ?? '';

        // 处理 RTSP / RTMP 协议（直接映射）
        if ($scheme === 'rtsp' || $scheme === 'rtsps') {
            return 'rtsp';
        }
        if ($scheme === 'rtmp' || $scheme === 'rtmps') {
            return 'rtmp';
        }

        // 处理 HTTP/HTTPS/WS/WSS
        if (in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            // HLS (TS)
            if (preg_match('/\/hls\.m3u8(?:$|\?)/i', $path)) {
                return 'hls';
            }
            // HLS (fMP4) —— 注意：ZLMediaKit 仍将其归为 hls schema
            if (preg_match('/\/hls\.fmp4\.m3u8(?:$|\?)/i', $path)) {
                return 'hls'; // 注意：API 返回也是 'hls'，不是 'fmp4'
            }

            // live.ts → ts
            if (preg_match('/\.live\.ts(?:$|\?)/i', $path)) {
                return 'ts';
            }

            // live.mp4 → fmp4
            if (preg_match('/\.live\.mp4(?:$|\?)/i', $path)) {
                return 'fmp4';
            }

            // live.flv → 实际属于 RtmpMediaSource，schema 为 'rtmp'
            if (preg_match('/\.live\.flv(?:$|\?)/i', $path)) {
                return 'rtmp';
            }

            // 普通 MP4 点播（非直播）不产生 MediaSource，或属于 record app，通常不在此列
            // 可忽略或返回 null
        }

        return null;
    }

    /**
     * 查询录像
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function queryPlayback(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在');
        }

        $startTime = $request->post('start_time', '');
        if (empty($startTime)) {
            return $this->createErrorJsonResponse('请指定开始时间', 400);
        }

        $endTime = $request->post('end_time', '');
        if (empty($endTime)) {
            return $this->createErrorJsonResponse('请指定结束时间', 400);
        }

        $startTimeTs = strtotime($startTime);
        $endTimeTs = strtotime($endTime);

        // 先删除查询时间范围内的旧记录（全量同步模式）
        $this->getPlaybackRecordService()->deleteRecordsInTimeRange(
            $channel['device_id'],
            $startTimeTs,
            $endTimeTs,
            $channel['channel_id']
        );

        $type = $request->post('type', 'all');
        $resp = $this->getGb28181Service()->queryRecord($channel['device_id'], $channel['channel_id'], $startTime, $endTime, $type);
        if (!$resp) {
            return $this->createErrorJsonResponse('查询录像失败');
        }

        return $this->createSuccessJsonResponse();
    }


    public function getRecordInfoResult(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在');
        }

        // 快速时间参数
        $quickTime = $request->get('quick_time', '');
        $startTime = $request->get('start_time', null);
        $endTime = $request->get('end_time', null);

        // 根据快速时间参数计算时间范围
        if ($quickTime) {
            $now = time();
            $endTime = $now;
            switch ($quickTime) {
                case 'today':
                    $startTime = strtotime(date('Y-m-d 00:00:00'));
                    break;
                case '7days':
                    $startTime = strtotime('-7 days');
                    break;
                case '15days':
                    $startTime = strtotime('-15 days');
                    break;
                case '30days':
                    $startTime = strtotime('-30 days');
                    break;
                case '6months':
                    $startTime = strtotime('-6 months');
                    break;
                default:
                    return $this->createErrorJsonResponse('无效的 quick_time 参数，支持: today, 7days, 15days, 30days, 6months');
            }
        } else {
            if (!$startTime || !$endTime) {
                return $this->createErrorJsonResponse('缺少必要参数: start_time, end_time 或 quick_time');
            }
            $startTime = strtotime($startTime);
            $endTime = strtotime($endTime);
        }

        // 获取录像列表和总数
        $conditions = [
            'device_id' => $channel['device_id'],
            'channel_id' => $channel['channel_id'],
            'start_time_LTE' => $endTime,
            'end_time_GTE' => $startTime,
        ];

        $total = $this->getPlaybackRecordService()->countPlaybackRecords($conditions);
        $records = $this->getPlaybackRecordService()->searchPlaybackRecords(
            $conditions,
            ['start_time' => 'ASC'],
            0,
            10000,
            ['id', 'name', 'file_path', 'start_time', 'end_time', 'secrecy', 'type', 'recorder_id']
        );

        // 格式化录像数据
        $formattedRecords = [];
        foreach ($records as $record) {
            $formattedRecords[] = [
                'id' => $record['id'],
                'name' => $record['name'],
                'file_path' => $record['file_path'],
                'start_time' => $record['start_time'],
                'end_time' => $record['end_time'],
                'start_time_format' => date('Y-m-d H:i:s', $record['start_time']),
                'end_time_format' => date('Y-m-d H:i:s', $record['end_time']),
                'secrecy' => $record['secrecy'],
                'type' => $record['type'],
                'recorder_id' => $record['recorder_id'],
            ];
        }

        return $this->createSuccessJsonResponse([
            'total' => $total,
            'list' => $formattedRecords,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    /**
     * 解析 ffprobe 返回的编码信息
     */
    private function parseCodecInfo(array $ffprobeResult): array
    {
        $info = [
            'video' => null,
            'audio' => null,
            'format' => null,
        ];

        // 解析格式信息
        if (isset($ffprobeResult['format'])) {
            $info['format'] = [
                'format_name' => $ffprobeResult['format']['format_name'] ?? '',
                'format_long_name' => $ffprobeResult['format']['format_long_name'] ?? '',
                'duration' => $ffprobeResult['format']['duration'] ?? '',
                'size' => $ffprobeResult['format']['size'] ?? '',
                'bit_rate' => $ffprobeResult['format']['bit_rate'] ?? '',
            ];
        }

        // 解析流信息
        if (isset($ffprobeResult['streams']) && is_array($ffprobeResult['streams'])) {
            foreach ($ffprobeResult['streams'] as $stream) {
                // 跳过没有 codec_name 的流（如数据流、元数据流等）
                if (!isset($stream['codec_name'])) {
                    continue;
                }

                $codecType = $stream['codec_type'] ?? '';

                if ($codecType === 'video') {
                    $info['video'] = [
                        'codec_name' => $stream['codec_name'] ?? '',
                        'codec_long_name' => $stream['codec_long_name'] ?? '',
                        'width' => $stream['width'] ?? 0,
                        'height' => $stream['height'] ?? 0,
                        'fps' => $this->parseFps($stream['r_frame_rate'] ?? ''),
                        'bit_rate' => $stream['bit_rate'] ?? '',
                        'pix_fmt' => $stream['pix_fmt'] ?? '',
                        'profile' => $stream['profile'] ?? '',
                    ];
                } elseif ($codecType === 'audio') {
                    $info['audio'] = [
                        'codec_name' => $stream['codec_name'] ?? '',
                        'codec_long_name' => $stream['codec_long_name'] ?? '',
                        'sample_rate' => $stream['sample_rate'] ?? '',
                        'channels' => $stream['channels'] ?? 0,
                        'channel_layout' => $stream['channel_layout'] ?? '',
                        'bit_rate' => $stream['bit_rate'] ?? '',
                        'sample_fmt' => $stream['sample_fmt'] ?? '',
                    ];
                }
            }
        }

        return $info;
    }

    /**
     * 解析帧率 (如 "25/1" -> 25.0, "30000/1001" -> 29.97)
     */
    private function parseFps(string $fpsString): float
    {
        if (empty($fpsString)) {
            return 0.0;
        }

        if (strpos($fpsString, '/') !== false) {
            [$numerator, $denominator] = explode('/', $fpsString);
            $fps = (float)$numerator / (float)$denominator;
            return round($fps, 2);
        }

        return (float)$fpsString;
    }

    /**
     * 过滤媒体服务器敏感信息，只保留必要的字段
     */
    private function filterMediaServerInfo(array $server): array
    {
        return [
            'id' => $server['id'],
            'name' => $server['name'] ?? '',
            'type' => $server['type'] ?? '',
            'host' => $server['host'] ?? '',
            'port' => $server['port'] ?? '',
        ];
    }


    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return MediaServerService
     */
    private function getMediaServerService(): MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    /**
     * @return PlaybackRecordService
     */
    private function getPlaybackRecordService(): PlaybackRecordService
    {
        return $this->createService('Devices:PlaybackRecordService');
    }

    private function getGb28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * @return FFProbe|null
     */
    protected function getFFProbe()
    {
        return $this->getBiz()['ffprobe'];
    }
}