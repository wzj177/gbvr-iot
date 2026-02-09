<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\DeviceFilter;
use CoreW\Business\Devices\Enums\ChannelTypeEnum;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\Subscribe\Service\SubscribeService;
use CoreW\Business\SystemLog\LogEnum;
use support\Redis;
use support\Request;
use support\Response;
use support\utils\ArrayToolkit;
use support\utils\Paginator;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\ServerSentEvents;
use Workerman\Timer;

/**
 * GB28181 设备管理控制器 - 管理后台
 */
class GB28181DeviceController extends BaseController
{
    /**
     * 获取设备列表
     */
    public function index(Request $request)
    {
        $conditions = [];
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        $total = $this->getDeviceService()->countDevices($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        // TODO：status 根据enum设置顺序排序
        $devices = $this->getDeviceService()->searchDevices($conditions, ['status' => 'ASC', 'id' => 'DESC'], $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'summary' => $this->getDeviceService()->summaryDevices($conditions),
            'list' => DeviceFilter::publicList($devices),
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取设备详情
     */
    public function show(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 获取通道列表
        $channels = $this->getDeviceService()->getChannelsByDeviceId($device['device_id']);
        $device['channels'] = $channels;

        return $this->createSuccessJsonResponse($device);
    }

    /**
     * 删除设备
     */
    public function destroy(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 删除设备和通道
        try {
            $this->getDeviceService()->deleteDeviceById($id);

            return $this->createSuccessJsonResponse([
                'message' => '设备已删除',
            ]);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_DELETE_DEVICE, "删除设备失败，{$e->getMessage()}", [
                'device_id' => $device['device_id'],
            ]);

            return $this->createErrorJsonResponse('删除设备失败', 500);
        }
    }

    /**
     * 查询设备目录（发送命令到信令网关）
     */
    public function queryCatalog(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        if ($device['status'] !== 'online') {
            return $this->createErrorJsonResponse('设备离线', 400);
        }

        // 发送命令到信令网关
        try {
            $result = $this->getGb28181Service()->queryCatalog($device['device_id']);

            if (!$result) {
                return $this->createErrorJsonResponse('发送目录查询请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送目录查询请求异常: ' . $e->getMessage(), 500);
        }

        $this->getLogService()->info('gb28181', 'query_catalog', '目录查询命令已发送', [
            'device_id' => $device['device_id'],
        ]);

        return $this->createSuccessJsonResponse([
            'message' => '目录查询命令已发送，请等待设备响应',
        ]);
    }


    public function startPlayback(Request $request, $id)
    {
        return $this->createSuccessJsonResponse([
            'message' => '开始回放',
        ]);
    }

    public function stopPlayback(Request $request, $id)
    {
        return $this->createSuccessJsonResponse([
            'message' => '停止回放',
        ]);
    }


    /**
     * 更新设备信息
     */
    public function update(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        $data = $request->post();
        $allowedFields = [
            // 基础配置
            'show_name',       // 自定义名称
            'rtp_trans_mode',  // RTP传输模式：0=UDP，1=TCP被动，2=TCP主动
            'enabled',

            // 行政区域
            'province_id',     // 省份代码（6位行政区划码）
            'city_id',         // 城市代码
            'county_id',       // 区县代码
            'custom_lat',
            'custom_lng',

            // 订阅配置
            'subscribe_catalog',  // 是否订阅目录变更
            'subscribe_alarm',    // 是否订阅报警事件
            'subscribe_position', // 是否订阅位置上报
            'subscribe_ptz',      // 是否订阅PTZ控制反馈(2022)
            'subscribe_expires',  // 订阅有效期（秒）
            'position_interval',  // 位置上报间隔（秒）

            // 通道更新配置
            'catalog_interval',  // 通道目录更新周期（秒），0=禁用轮询

            // 字符集和码流
            'charset',       // 设备XML字符集: gb2312, utf8
            'stream_index',  // 码流索引: auto=自动, 0=主码流, 1=子码流

            // 通道过滤
            'filter_channel_types',  // 过滤的通道类型列表，如[134,135]
            'senior_sdp'
        ];

        // 过滤只允许更新的字段
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('没有可更新的字段', 400);
        }

        // 验证 rtp_trans_mode
        if (isset($updateData['rtp_trans_mode'])) {
            $updateData['rtp_trans_mode'] = (int)$updateData['rtp_trans_mode'];
            if (!in_array($updateData['rtp_trans_mode'], [0, 1, 2])) {
                return $this->createErrorJsonResponse('RTP传输模式无效，必须为 0(UDP)、1(TCP被动) 或 2(TCP主动)', 400);
            }
        }

        // 验证 charset
        if (isset($updateData['charset'])) {
            if (!in_array($updateData['charset'], ['gb2312', 'utf8', 'auto'])) {
                return $this->createErrorJsonResponse('字符集无效，必须为 gb2312 或 utf8', 400);
            }
        }

        // 验证 stream_index
        if (isset($updateData['stream_index'])) {
            if (!in_array($updateData['stream_index'], ['auto', '0', '1'])) {
                return $this->createErrorJsonResponse('码流索引无效，必须为 auto、0 或 1', 400);
            }
        }

        // 验证布尔字段
        $booleanFields = ['subscribe_catalog', 'subscribe_alarm', 'subscribe_position', 'subscribe_ptz', 'enabled'];
        foreach ($booleanFields as $field) {
            if (isset($updateData[$field])) {
                $updateData[$field] = (int)$updateData[$field];
            }
        }

        // 验证整数字段
        $intFields = ['subscribe_expires', 'position_interval', 'catalog_interval'];
        foreach ($intFields as $field) {
            if (isset($updateData[$field])) {
                $updateData[$field] = (int)$updateData[$field];
                if ($updateData[$field] < 0) {
                    return $this->createErrorJsonResponse("{$field} 必须大于等于 0", 400);
                }
            }
        }

        try {
            // 检测订阅配置字段变化
            $subscriptionFields = [
                'subscribe_catalog', 'subscribe_alarm', 'subscribe_position',
                'subscribe_ptz', 'subscribe_expires', 'position_interval'
            ];
            $hasSubscriptionUpdate = !empty(array_intersect_key($updateData, array_flip($subscriptionFields)));

            // 更新设备信息
            $this->getDeviceService()->updateDeviceExtendInfo($id, $updateData);

            // 如果订阅配置有变化，调用订阅服务
            if ($hasSubscriptionUpdate) {
                try {
                    // 构建订阅配置
                    $subscribeConfig = [
                        'event_catalog' => (int)($updateData['subscribe_catalog'] ?? 0),
                        'event_alarm' => (int)($updateData['subscribe_alarm'] ?? 0),
                        'event_mobile_position' => (int)($updateData['subscribe_position'] ?? 0),
                        'subscribe_expires' => (int)($updateData['subscribe_expires'] ?? 3600),
                        'mobile_interval_sec' => (int)($updateData['position_interval'] ?? 5),
                        'status' => 1,  // 默认启用
                    ];

                    $this->getSubscribeService()->saveSubscribeConfig($device['device_id'], null, $subscribeConfig);

                    $this->getLogService()->info('subscribe', 'update_subscribe', '设备订阅配置已更新', [
                        'device_id' => $device['device_id'],
                        'config' => $subscribeConfig,
                    ]);
                } catch (\Exception $e) {
                    $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_UPDATE_SUBSCRIBE, '更新订阅配置失败: ' . $e->getMessage(), [
                        'device_id' => $device['device_id'],
                    ]);
                    // 订阅失败不影响设备更新成功
                }
            }

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_UPDATE, '更新设备失败: ' . $e->getMessage(), [
                'id' => $id,
            ]);

            return $this->createErrorJsonResponse('更新设备失败: ' . $e->getMessage(), 500);
        }
    }

    public function cmd(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);
        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // cmd
        $cmd = $request->post('cmd', '');
        if (empty($cmd)) {
            return $this->createErrorJsonResponse('请指定要执行的命令', 400);
        }

        $result = match ($cmd) {
            'query_device_info' => $this->getGb28181Service()->queryDeviceInfo($device['device_id']),
            'query_record' => (function($request, $device){
                $startTime = $request->post('start_time', '');
                if (empty($startTime)) {
                    return $this->createErrorJsonResponse('请指定开始时间', 400);
                }

                $endTime = $request->post('end_time', '');
                if (empty($endTime)) {
                    return $this->createErrorJsonResponse('请指定结束时间', 400);
                }

                $channelId = $request->post('channel_id', '');
                if (empty($channelId)) {
                    return $this->createErrorJsonResponse('请指定通道ID', 400);
                }

                $type = $request->post('type', 'all');

                return $this->getGb28181Service()->queryRecord($device['device_id'], $channelId, $startTime, $endTime, $type);
            })($request, $device),
            default => $this->createErrorJsonResponse('不支持的命令', 400),
        };
        return $this->createSuccessJsonResponse([
            'result' => $result,
        ], '命令已发送，请等待设备响应');
    }

    /**
     * 批量设置设备地区
     */
    public function batchUpdateArea(Request $request)
    {
        $ids = $request->post('ids', []);
        $provinceId = $request->post('province_id', '');
        $cityId = $request->post('city_id', '');
        $countyId = $request->post('county_id', '');

        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要设置的设备');
        }

        $updateData = [];
        if (!empty($provinceId)) {
            $updateData['province_id'] = $provinceId;
        }
        if (!empty($cityId)) {
            $updateData['city_id'] = $cityId;
        }
        if (!empty($countyId)) {
            $updateData['county_id'] = $countyId;
        }

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('请至少设置一个地区参数');
        }

        try {
            $successCount = 0;
            foreach ($ids as $id) {
                $device = $this->getDeviceService()->getDevicesById($id);
                if ($device) {
                    $this->getDeviceService()->updateDevice($id, $updateData);
                    $successCount++;
                }
            }

            $this->getLogService()->info('gb28181', 'batch_update_area', "批量设置设备地区，成功: {$successCount}个", [
                'ids' => $ids,
                'updateData' => $updateData,
            ]);

            return $this->createSuccessJsonResponse([
                'successCount' => $successCount,
                'message' => "成功设置 {$successCount} 个设备的地区信息",
            ]);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_BATCH_UPDATE_AREA, '批量设置设备地区失败: ' . $e->getMessage(), [
                'ids' => $ids,
            ]);

            return $this->createErrorJsonResponse('批量设置地区失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取设备树形数据
     */
    public function tree(Request $request)
    {
        $treeType = $request->get('tree_type', 'dc');

        if (!in_array($treeType, ['dc', 'area'])) {
            return $this->createErrorJsonResponse('tree_type 参数无效，必须是 dc 或 area');
        }

        try {
            $tree = $this->getDeviceService()->getDeviceTree($treeType);

            return $this->createSuccessJsonResponse($tree);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_UPDATE, '获取设备树失败: ' . $e->getMessage(), [
                'tree_type' => $treeType,
            ]);

            return $this->createErrorJsonResponse('获取设备树失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * SSE 流式返回设备的 SIP XML 文件内容
     * 文件路径: runtime/sip/xml/{device_id}-{ymd}.log
     */
    public function eventStream(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        $deviceId = $device['device_id'];

        // 支持指定日期，默认今天
        $date = $request->get('date', date('Ymd'));

        // 验证日期格式
        if (!preg_match('/^\d{8}$/', $date)) {
            return $this->createErrorJsonResponse('日期格式无效，必须是 Ymd 格式，如 20260118', 400);
        }

        $connection = $request->connection;
        $xmlPath = runtime_path('sip/xml/' . $deviceId . '-' . $date . '.log');

        // 检查文件是否存在，读取内容
        $lines = [];
        $error = null;

        if (!file_exists($xmlPath)) {
            $error = '文件不存在';
        } else {
            $content = file_get_contents($xmlPath);
            if ($content === false) {
                $error = '读取文件失败';
            } else {
                $trimmed = trim($content);
                if (!empty($trimmed)) {
                    $lines = explode("\n", $trimmed);
                }
            }
        }

        $totalLines = count($lines);
        $currentIndex = 0;
        $sentCount = 0;  // 记录已发送次数

        // 使用定时器逐行发送
        $timerId = Timer::add(1, function () use ($connection, &$timerId, $lines, &$currentIndex, $totalLines, $deviceId, $date, $error, &$sentCount) {
            // 第一次发送前不检查连接状态，让连接先建立
            if ($sentCount > 0 && $connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                Timer::del($timerId);
                return;
            }

//            $connection->send(new ServerSentEvents(['data' => 'hello']));

            // 如果有错误且没有数据，发送错误消息
            if ($error !== null && $totalLines === 0 && $sentCount === 0) {
                $connection->send(new ServerSentEvents([
                    'event' => 'error',
                    'data' => $error,
                ]));
                $sentCount++;
                Timer::del($timerId);
                return;
            }

            // 发送数据
            if ($currentIndex < $totalLines) {
                // 每次发送多行
                $batchSize = 10;
                $batchLines = [];
                for ($i = 0; $i < $batchSize && $currentIndex < $totalLines; $i++, $currentIndex++) {
                    $line = trim($lines[$currentIndex]);
                    if (!empty($line)) {
                        $batchLines[] = $line;
                    }
                }

                if (!empty($batchLines)) {
                    $content = implode("\n", $batchLines);
                    $connection->send(new ServerSentEvents([
                        'data' => $content,
                    ]));
                    $sentCount++;
                }
            } elseif ($sentCount > 0) {
                // 数据发送完毕，发送结束消息
                $connection->send(new ServerSentEvents([
                    'event' => 'end',
                    'data' => 'done',
                ]));
                Timer::del($timerId);
            } else {
                // 文件为空，发送提示
                $connection->send(new ServerSentEvents([
                    'event' => 'end',
                    'data' => 'done',
                ]));
                $sentCount++;
                Timer::del($timerId);
            }
        });

        return response('', 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }


    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * @return SubscribeService
     */
    private function getSubscribeService(): SubscribeService
    {
        return $this->createService('Subscribe:SubscribeService');
    }
}