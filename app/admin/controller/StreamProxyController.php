<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\StreamProxy\Service\StreamProxyService;
use CoreW\Business\StreamProxy\Exception\StreamProxyException;
use CoreW\Business\Common\CommonBizException;
use support\Request;
use support\Response;
use support\utils\Paginator;

/**
 * 流代理管理控制器 - 管理后台
 */
class StreamProxyController extends BaseController
{
    /**
     * 获取流代理列表
     */
    public function index(Request $request) : Response
    {
        $conditions = [];

        // Filter by status
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        // Filter by type
        if ($request->get('type')) {
            $conditions['type'] = $request->get('type');
        }

        // Filter by protocol
        if ($request->get('protocol')) {
            $conditions['protocol'] = $request->get('protocol');
        }

        // Filter by media server
        if ($request->get('media_server_id')) {
            $conditions['mediaServerId'] = $request->get('media_server_id');
        }

        // Filter by record plan
        if ($request->get('record_plan_id')) {
            $conditions['recordPlanId'] = $request->get('record_plan_id');
        }

        // Search keyword
        if ($request->get('keyword')) {
            $conditions['keywords'] = '%' . $request->get('keyword') . '%';
        }

        $total = $this->getStreamProxyService()->countProxies($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);

        $orderBys = ['id' => 'DESC'];
        if ($request->get('sort')) {
            switch ($request->get('sort')) {
                case 'created_at':
                    $orderBys = ['created_at' => 'DESC'];
                    break;
                case 'started_at':
                    $orderBys = ['started_at' => 'DESC'];
                    break;
                case 'last_heartbeat_at':
                    $orderBys = ['last_heartbeat_at' => 'DESC'];
                    break;
            }
        }

        $proxies = $this->getStreamProxyService()->searchProxies($conditions, $orderBys, $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $proxies,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 创建流代理
     */
    public function create(Request $request) : Response
    {
        try {
            $fields = $request->post();
            $proxy = $this->getStreamProxyService()->createProxy($fields);

            return $this->createSuccessJsonResponse($proxy, '流代理创建成功', 201);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (CommonBizException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('创建失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 获取流代理详情
     */
    public function show(Request $request, $id) : Response
    {
        $proxy = $this->getStreamProxyService()->getProxy((int)$id);

        if (!$proxy) {
            return $this->createErrorJsonResponse('流代理不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($proxy);
    }

    /**
     * 更新流代理
     */
    public function update(Request $request, $id) : Response
    {
        try {
            $fields = $request->post();
            $result = $this->getStreamProxyService()->updateProxy((int)$id, $fields);

            if ($result) {
                $proxy = $this->getStreamProxyService()->getProxy((int)$id);
                return $this->createSuccessJsonResponse($proxy, '更新成功');
            }

            return $this->createErrorJsonResponse('更新失败', null, -1, 500);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('更新失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 删除流代理
     */
    public function destroy(Request $request, $id) : Response
    {
        try {
            $result = $this->getStreamProxyService()->deleteProxy((int)$id);

            if ($result) {
                return $this->createSuccessJsonResponse(['message' => '流代理已删除']);
            }

            return $this->createErrorJsonResponse('删除失败', null, -1, 500);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 404);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('删除失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 启动流代理
     */
    public function start(Request $request, $id) : Response
    {
        try {
            $proxy = $this->getStreamProxyService()->startProxy((int)$id);

            return $this->createSuccessJsonResponse($proxy, '流代理已启动');
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('启动失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 停止流代理
     */
    public function stop(Request $request, $id) : Response
    {
        try {
            $result = $this->getStreamProxyService()->stopProxy((int)$id);

            if ($result) {
                return $this->createSuccessJsonResponse(['message' => '流代理已停止']);
            }

            return $this->createErrorJsonResponse('停止失败', null, -1, 500);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('停止失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 重启流代理
     */
    public function restart(Request $request, $id) : Response
    {
        try {
            $proxy = $this->getStreamProxyService()->restartProxy((int)$id);

            return $this->createSuccessJsonResponse($proxy, '流代理已重启');
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('重启失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 获取播放地址
     */
    public function playUrls(Request $request, $id) : Response
    {
        try {
            $urls = $this->getStreamProxyService()->getPlayUrls((int)$id);

            return $this->createSuccessJsonResponse($urls);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 404);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('获取播放地址失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 获取推流地址
     */
    public function pushUrl(Request $request, $id) : Response
    {
        try {
            $pushInfo = $this->getStreamProxyService()->getPushUrl((int)$id);

            return $this->createSuccessJsonResponse($pushInfo);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('获取推流地址失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 绑定录像计划
     */
    public function bindPlan(Request $request, $id) : Response
    {
        try {
            $planId = (int)$request->post('record_plan_id');

            if (!$planId) {
                return $this->createErrorJsonResponse('请提供录像计划ID', null, -1, 400);
            }

            $result = $this->getStreamProxyService()->bindRecordPlan((int)$id, $planId);

            if ($result) {
                return $this->createSuccessJsonResponse(['message' => '录像计划已绑定']);
            }

            return $this->createErrorJsonResponse('绑定失败', null, -1, 500);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('绑定失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 解绑录像计划
     */
    public function unbindPlan(Request $request, $id) : Response
    {
        try {
            $result = $this->getStreamProxyService()->unbindRecordPlan((int)$id);

            if ($result) {
                return $this->createSuccessJsonResponse(['message' => '录像计划已解绑']);
            }

            return $this->createErrorJsonResponse('解绑失败', null, -1, 500);
        } catch (StreamProxyException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 404);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('解绑失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 统计摘要
     */
    public function summary(Request $request) : Response
    {
        $total = $this->getStreamProxyService()->countProxies([]);
        $online = $this->getStreamProxyService()->countProxies(['status' => 'online']);
        $offline = $this->getStreamProxyService()->countProxies(['status' => 'offline']);
        $stopped = $this->getStreamProxyService()->countProxies(['status' => 'stopped']);
        $error = $this->getStreamProxyService()->countProxies(['status' => 'error']);

        $pullType = $this->getStreamProxyService()->countProxies(['type' => 'pull']);
        $pushType = $this->getStreamProxyService()->countProxies(['type' => 'push']);

        $withRecordPlan = $this->getStreamProxyService()->countProxies(['recordPlanId_GT' => 0]);
        $recording = $this->getStreamProxyService()->countProxies(['recordStatus' => 1]);

        return $this->createSuccessJsonResponse([
            'total'     => $total,
            'by_status' => [
                'online'  => $online,
                'offline' => $offline,
                'stopped' => $stopped,
                'error'   => $error,
            ],
            'by_type'   => [
                'pull' => $pullType,
                'push' => $pushType,
            ],
            'recording' => [
                'with_plan' => $withRecordPlan,
                'recording' => $recording,
            ],
        ]);
    }

    /**
     * 批量健康检查（手动触发）
     */
    public function healthCheck(Request $request) : Response
    {
        try {
            $result = $this->getStreamProxyService()->batchHealthCheck();

            return $this->createSuccessJsonResponse($result);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('健康检查失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 获取服务
     */
    /**
     * 获取流代理日志列表
     */
    public function logs(Request $request, $id) : Response
    {
        $id = (int)$id;
        $proxy = $this->getStreamProxyService()->getProxy($id);

        if (!$proxy) {
            return $this->createErrorJsonResponse('流代理不存在', null, -1, 404);
        }

        $start = (int)$request->get('start', 0);
        $limit = (int)$request->get('limit', 20);
        $limit = min($limit, 100); // Max 100 per page

        $logs = $this->getStreamProxyService()->getProxyLogs($id, $start, $limit);
        $total = $this->getStreamProxyService()->countLogs(['proxy_id' => $proxy['proxy_id']]);

        $paginator = new Paginator(1, $total, $request->uri(), $limit);
        return $this->createSuccessJsonResponse([
            'list'      => $logs,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 获取所有流代理日志列表（支持筛选）
     */
    public function allLogs(Request $request) : Response
    {
        $conditions = [];

        // Filter by proxy_id
        if ($request->get('proxy_id')) {
            $conditions['proxy_id'] = $request->get('proxy_id');
        }

        // Filter by event_type
        if ($request->get('event_type')) {
            $conditions['event_type'] = $request->get('event_type');
        }

        // Filter by level
        if ($request->get('level')) {
            $conditions['level'] = $request->get('level');
        }

        // Filter by date range
        if ($request->get('start_date')) {
            $conditions['created_at_GE'] = $request->get('start_date') . ' 00:00:00';
        }

        if ($request->get('end_date')) {
            $conditions['created_at_LE'] = $request->get('end_date') . ' 23:59:59';
        }

        // Search keyword in message
        if ($request->get('keyword')) {
            $conditions['message_LIKE'] = '%' . $request->get('keyword') . '%';
        }

        $start = (int)$request->get('start', 0);
        $limit = (int)$request->get('limit', 20);
        $limit = min($limit, 100); // Max 100 per page

        $orderBys = ['created_at' => 'DESC'];

        $logs = $this->getStreamProxyService()->searchLogs($conditions, $orderBys, $start, $limit);
        $total = $this->getStreamProxyService()->countLogs($conditions);

        $paginator = new Paginator(1, $total, $request->uri(), $limit);
        return $this->createSuccessJsonResponse([
            'list'      => $logs,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 清理旧日志
     */
    public function cleanupLogs(Request $request) : Response
    {
        $daysToKeep = (int)$request->post('days_to_keep', 30);

        if ($daysToKeep < 7) {
            return $this->createErrorJsonResponse('保留天数不能少于7天');
        }

        $deletedCount = $this->getStreamProxyService()->cleanupOldLogs($daysToKeep);

        return $this->createSuccessJsonResponse([
            'deleted_count' => $deletedCount,
            'days_to_keep'  => $daysToKeep,
        ], "已清理 {$deletedCount} 条日志");
    }

    protected function getStreamProxyService() : StreamProxyService
    {
        return $this->createService('StreamProxy:StreamProxyService');
    }
}
