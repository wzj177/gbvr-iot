<?php


namespace app;

use CoreW\Business\Setting\Service\SettingService;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Bfw;
use CoreW\Core;
use CoreW\Exception\BadRequestHttpException;
use CoreW\Traits\RequestAndResponseTrait;
use support\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 *
 * Class AbstractController
 * @package app\api
 */
abstract class AbstractController
{

    /**
     * 接口成功业务码
     */
    const BIS_SUCCESS_CODE = 0;

    /**
     * 未匹配到业务错误码的统一业务码
     */
    const BIS_FAILED_CODE = -1;

    /**
     *  请求方法错误业务码
     */
    const ERROR_CODE_METHOD_FAILED = 4041001;

    /**
     * 无权限访问业务码
     */
    const ERROR_CODE_NOT_ALLOW_FAILED = 4031001;

    /**
     * post 请求 必要参数缺失
     */
    const ERROR_CODE_POST_DATA_FAILED = 4001001;

    /**
     * get 请求 必要参数缺少或错误
     */
    const ERROR_CODE_GET_DATA_FAILED = 4001002;

    /**
     *
     */
    const DEFAULT_PAGING_LIMIT = 10;
    /**
     *
     */
    const DEFAULT_PAGING_OFFSET = 0;
    const DEFAULT_PAGING_PAGE = 1;
    /**
     *
     */
    const MAX_PAGING_LIMIT = 500;

    /**
     *
     */
    const PREFIX_SORT_DESC = '-';

    use RequestAndResponseTrait;

    public function beforeAction(Request $request)
    {
    }

    public function afterAction(Request $request, $response)
    {
    }

    /**
     *
     * 检查每个API必需参数的完整性
     * @param $requiredFields
     * @param $requestData
     * @return mixed
     */
    protected function checkRequiredFields($requiredFields, $requestData)
    {
        $requestFields = array_keys($requestData);
        foreach ($requiredFields as $field) {
            if (!in_array($field, $requestFields)) {
                throw new BadRequestHttpException("缺少必需的请求参数{$field}", null, self::ERROR_CODE_POST_DATA_FAILED);
            }
        }

        return $requestData;
    }

    protected function dispatchEvent(string $eventName, $subject, $arguments = []): object
    {
        if ($subject instanceof Event) {
            $event = $subject;
        } else {
            $event = new Event($subject, $arguments);
        }
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $this->getBiz()->offsetGet('dispatcher');

        return $dispatcher->dispatch($event, $eventName);
    }

    /**
     *
     * @return Bfw
     */
    final protected function getBiz()
    {
        return Core::instance();
    }

    protected function createService($serviceAlias)
    {
        return $this->getBiz()->service($serviceAlias);
    }

    /**
     * @return SystemLogService
     */
    protected function getLogService(): SystemLogService
    {
        return $this->createService('SystemLog:SystemLogService');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService(): SettingService
    {
        return $this->createService('Setting:SettingService');
    }
}