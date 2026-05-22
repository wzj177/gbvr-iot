<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\SystemLogFilter;
use CoreW\Mail\AbstractMail;
use support\Redis;
use support\Request;
use support\utils\Paginator;
use support\utils\SimpleValidator;

class SystemController extends BaseController
{
    public function index(Request $request)
    {
        return response(__CLASS__);
    }

    public function logs(Request $request)
    {
        $conditions = $request->get();
        if (!empty($conditions['keyword'])) {
            $conditions['keywordsLike'] = $conditions['keyword'];
        }
        $total = $this->getLogService()->countLogs($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);
        $logs = $this->getLogService()->searchLogs(
            $conditions,
            ['id' => 'DESC'],
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );


        return $this->createSuccessJsonResponse(
            [
                'list'      => SystemLogFilter::publicList($logs),
                'paginator' => Paginator::toArray($paginator),
            ]
        );
    }

    /**
     * @param Request $request
     * @param $id
     * @return \support\Response
     */
    public function log(Request $request, $id)
    {
        $log = $this->getLogService()->getLogById($id);
        $filter = new SystemLogFilter();
        $filter->filter($log);
        return $this->createSuccessJsonResponse($log);
    }

    /**
     * @param Request $request
     * @return \support\Response
     */
    public function logModuleOptions(Request $request)
    {
        return $this->createSuccessJsonResponse($this->getLogService()->getModuleOptions());
    }

    /**
     * @param Request $request
     * @param $module
     * @return \support\Response
     */
    public function logActionOptions(Request $request, $module)
    {
        return $this->createSuccessJsonResponse($this->getLogService()->getModuleActionOptions($module));
    }

    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);

        if ($this->getLogService()->batchDelete($ids)) {
            return $this->createSuccessJsonResponse(null, '批量删除成功');
        }

        return $this->createErrorJsonResponse('批量删除失败');
    }

    public function clearCache(Request $request)
    {
        $redisChannels = config('redis', []);
        if (isset($redisChannels['default'])) {
            Redis::connection()->flushdb();
        }

        if (isset($redisChannels['dao-cache'])) {
            Redis::connection('dao-cache')->flushdb();
        }

        return $this->createSuccessJsonResponse();
    }


    public function testMail(Request $request)
    {
        $toMail = $request->post('to');
        if (empty($toMail) || !SimpleValidator::email($toMail)) {
            return $this->createErrorJsonResponse('邮箱格式错误');
        }

        $mailOptions = [
            'to'       => $toMail,
            'toName'   => '用户你好',
            'template' => 'email_system_self_test',
        ];
        $mailFactory = $this->getBiz()->offsetGet('mail_factory');
        /** @var $mail AbstractMail */
        $mail = $mailFactory($mailOptions);
        try {
            $mail->send();
            return $this->createSuccessJsonResponse();
        } catch (\Throwable $e) {
            return $this->createErrorJsonResponse("发送失败：{$e->getMessage()}");
        }
        //        // 队列名
        //        $queue = 'send-system-test-mail';
        //        // 投递消息
        //        Client::send($queue, $mailOptions);

        //        return $this->createSuccessJsonResponse();
    }


}
