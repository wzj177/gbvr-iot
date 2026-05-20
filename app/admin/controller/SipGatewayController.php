<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\SipGateway\Exception\SipGatewayException;
use CoreW\Business\SipGateway\Service\SipGatewayService;
use support\Request;
use support\Response;
use support\utils\Paginator;

class SipGatewayController extends BaseController
{
    public function index(Request $request) : Response
    {
        $conditions = [];

        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }
        if ($request->get('mq_type')) {
            $conditions['mq_type'] = $request->get('mq_type');
        }
        if ($request->get('gateway_name')) {
            $conditions['gateway_name_like'] = '%' . $request->get('gateway_name') . '%';
        }

        $total = $this->getSipGatewayService()->countGateways($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);

        $orderBys = ['id' => 'DESC'];
        $gateways = $this->getSipGatewayService()->searchGateways($conditions, $orderBys, $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $gateways,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    public function show(Request $request, $id) : Response
    {
        $gateway = $this->getSipGatewayService()->getGateway((int)$id);
        if (!$gateway) {
            return $this->createErrorJsonResponse('SIP网关不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($gateway);
    }

    public function store(Request $request) : Response
    {
        try {
            $fields = $request->post();
            $gateway = $this->getSipGatewayService()->createGateway($fields);

            return $this->createSuccessJsonResponse($gateway, '创建成功', 201);
        } catch (SipGatewayException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (CommonBizException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('创建失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    public function update(Request $request, $id) : Response
    {
        try {
            $fields = $request->post();
            $gateway = $this->getSipGatewayService()->updateGateway((int)$id, $fields);

            return $this->createSuccessJsonResponse($gateway, '更新成功');
        } catch (SipGatewayException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (CommonBizException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('更新失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    public function destroy(Request $request, $id) : Response
    {
        try {
            $this->getSipGatewayService()->deleteGateway((int)$id);

            return $this->createSuccessJsonResponse(null, '删除成功');
        } catch (SipGatewayException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('删除失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    public function toggle(Request $request, $id) : Response
    {
        try {
            $gateway = $this->getSipGatewayService()->toggleGateway((int)$id);

            return $this->createSuccessJsonResponse($gateway, '操作成功');
        } catch (SipGatewayException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('操作失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 绑定设备到网关（单个/批量）
     * POST /api/admin/sip-gateways/bind
     */
    public function bindDevices(Request $request) : Response
    {
        try {
            $gatewayId = $request->post('gateway_id', '');
            $deviceIds = $request->post('device_ids', []);

            if (empty($gatewayId)) {
                return $this->createErrorJsonResponse('gateway_id参数缺失', null, -1, 400);
            }
            if (empty($deviceIds) || !is_array($deviceIds)) {
                return $this->createErrorJsonResponse('device_ids参数缺失或格式错误', null, -1, 400);
            }

            $result = $this->getSipGatewayService()->bindDevicesToGateway($deviceIds, $gatewayId);

            return $this->createSuccessJsonResponse($result, '绑定完成');
        } catch (SipGatewayException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (CommonBizException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('绑定失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 解绑设备（单个/批量）
     * POST /api/admin/sip-gateways/unbind
     */
    public function unbindDevices(Request $request) : Response
    {
        try {
            $deviceIds = $request->post('device_ids', []);

            if (empty($deviceIds) || !is_array($deviceIds)) {
                return $this->createErrorJsonResponse('device_ids参数缺失或格式错误', null, -1, 400);
            }

            $result = $this->getSipGatewayService()->unbindDevicesFromGateway($deviceIds);

            return $this->createSuccessJsonResponse($result, '解绑完成');
        } catch (CommonBizException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('解绑失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    protected function getSipGatewayService() : SipGatewayService
    {
        return $this->createService('SipGateway:SipGatewayService');
    }
}
