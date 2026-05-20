<?php

namespace app\api\v1\controller;

use app\admin\filters\CompanyIotConfigFilter;
use app\api\BaseController;
use app\api\filters\VIPCompanyFilter;
use app\api\filters\VIPFilter;
use CoreW\Business\BizEnum;
use CoreW\Business\VIP\Exception\VIPException;
use support\Request;
use support\Response;
use support\utils\ArrayToolkit;

class VIPController extends BaseController
{
    public function index(Request $request)
    {
        return response(__CLASS__);
    }

    public function info(Request $request)
    {
        $vip = $this->getVIPService()->getVIPByUUID($this->getVIPInfo()->getUUID());
        $filter = new VIPFilter(VIPFilter::SIMPLE_MODE);
        $filter->filter($vip);
        $vipCompany = $this->getVIPService()->getVIPCompanyByUserId($vip['id']);
        if (!empty($vipCompany)) {
            $vipCompany = VIPCompanyFilter::publicData($vipCompany);
            $vip['company'] = $vipCompany;
        } else {
            $vip['company'] = null;
        }

        return $this->createSuccessJsonResponse($vip);
    }

    public function edit(Request $request, $id) : \support\Response
    {
        if ($this->getVIPInfo()->getUUID() !== $id) {
            return $this->createErrorJsonResponse('非法访问', null, -1, 403);
        }

        $fields = $request->post();
        $result = $this->getVIPService()->editVIPInfo($id, $fields);
        if ($result) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse('个人资料更新失败,请联系管理员');
    }

    /**
     * 发送验证申请
     *
     * @param Request $request
     * @return \support\Response
     */
    public function sendEmailVerify(Request $request)
    {
        $vip = $this->getVIPInfo()->toArray();
        if ($vip['emailVerified']) {
            return $this->createErrorJsonResponse('会员邮箱已验证，无须再次验证');
        }

        if ($this->getVIPService()->sendEmailVerifyNotification($vip)) {
            return $this->createSuccessJsonResponse([], '验证申请已发送至您的邮箱，请前往邮箱验证');
        }

        return $this->createErrorJsonResponse('验证申请发送失败');
    }

    /**
     * 邮箱验证
     * @param Request $request
     * @return \support\Response
     * @throws \CoreW\Dao\DaoException
     */
    public function emailVerify(Request $request)
    {
        $token = $request->get('token');
        if (empty($token)) {
            return $this->eamilVerifyResponse($request, -2);
        }

        $result = $this->getVIPService()->emailVerify($token);

        return $this->eamilVerifyResponse($request, $result);
    }

    public function getCompany(Request $request, $uid) : Response
    {
        $companyInfo = $this->getVIPService()->getVIPCompanyByUserId(intval($uid));
        if (!$companyInfo) {
            return $this->createSuccessJsonResponse(null);
        }

        return $this->createSuccessJsonResponse(VIPCompanyFilter::simpleData($companyInfo));
    }

    public function applyCompany(Request $request) : Response
    {
        $userId = (int)$this->getVIPInfo()->getId();
        $result = $this->getVIPService()->applyCompany($userId, $request->post());
        if ($result) {
            return $this->createSuccessJsonResponse([], '申请成功，请等待管理员审核');
        }

        return $this->createErrorJsonResponse('申请失败');
    }

    public function getCompanyIotConfig(Request $request, $id)
    {
        $iotConfig = $this->getVIPService()->getCompanyIotConfig((int)$id);

        return $this->createSuccessJsonResponse(CompanyIotConfigFilter::publicData($iotConfig));

    }

    public function companyIotConfig(Request $request, $id) : Response
    {
        $userId = (int)$this->getVIPInfo()->getId();
        $result = $this->getVIPService()->setCompanyIotConfig((int)$id, $userId, $request->post());
        if ($result) {
            return $this->createSuccessJsonResponse([], '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function companyIotApiList(Request $request) : Response
    {
        $list = ArrayToolkit::enumToList(BizEnum::getCompanyIotApiItems());

        return $this->createSuccessJsonResponse($list);
    }

    public function companyIotServiceList(Request $request) : Response
    {
        $serviceList = config('iot');
        $items = [];
        foreach ($serviceList as $key => $service) {
            $items[] = [
                'key'     => $key,
                'title'   => $service['name'],
                'api_map' => $service['apiMap'],
            ];
        }

        return $this->createSuccessJsonResponse($items);
    }

    /**
     * TODO: 需要修改跳转链接
     *
     * @param Request $request
     * @param $code
     * @return \support\Response
     */
    protected function eamilVerifyResponse(Request $request, $code)
    {
        if ($code === -2) {
            if ($request->isAjax()) {
                return $this->createErrorJsonResponse('not found resource', null, -1, 404);
            }

            return view('404', [
                'error' => '<a href="/" style="text-decoration:none;line-height:100%;background:#5865f2;color:white;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:15px;font-weight:normal;text-transform:none;margin:0px;" >返回首页</a>',
            ]);
        }

        if ($code === -1) {
            if ($request->isAjax()) {
                return $this->createErrorJsonResponse('链接已失效', null, VIPException::EMAIL_VERIFY_LINK_INVALID);
            }

            if ($request->isPc()) {
                return redirect('/index.html?email_verify=invalid');
            }

            return redirect('/h5/vip/invalid_email');
        }

        if ($request->isAjax()) {
            return $this->createSuccessJsonResponse([]);
        }

        if ($request->isPc()) {
            return redirect('/index.html?email_verify=success');
        }

        return redirect('/h5/vip/success_email');
    }
}
