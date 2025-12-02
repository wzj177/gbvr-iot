<?php


namespace CoreW\LiveProvider\Strategy;


use CoreW\Business\Setting\Service\SettingService;
use CoreW\Bfw;
use CoreW\Business\SystemLog\Service\SystemLogService;

class LiveProviderStrategy
{
    protected $CoreW;

    protected $currentThirdParty;

    public function __construct(Bfw $CoreW)
    {
        $this->CoreW = $CoreW;
    }

    public function __destruct()
    {
        $this->CoreW = null;
    }

    /**
     *
     * 获取视频截图
     * @param $code
     * @param $otherParam
     * @return string|null
     */
    public function getVideoCover($code, $otherParam = null)
    {
    }

    public function activeAndOpenLiveWithCameras(array $conditions, $sort, $offset, $limit, $options = [])
    {

    }

    public function openLiveWithCameras(array $conditions, array $options = [])
    {

    }

    public function deviceTrees(array $conditions = [])
    {

    }

    public function countVideoChannels(array $conditions)
    {

    }

    public function countRecorders(array $conditions)
    {

    }

    public function searchRecorders($offset, $limit, array $conditions = [], $sort = null, $columns = [])
    {

    }

    public function searchCameras($offset, $limit, array $conditions = [], $sort = null, $columns = [])
    {

    }

    /**
     *
     *
     * 开发平台必须匹配
     * @param null $currentThirdParty
     * @return LiveProviderStrategy
     */
    public function setCurrentThirdParty($currentThirdParty = null): LiveProviderStrategy
    {
        $this->currentThirdParty = $currentThirdParty;

        return $this;
    }

    /**
     * @return QueueService
     */
    protected function getQueueService()
    {
        return $this->createService('Queue:QueueService');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->createService('Setting:SettingService');
    }

    /**
     * @return SystemLogService
     */
    protected function getSystemLogService()
    {
        return $this->createService('SystemLog:SystemLogService');
    }

    protected function createService($alias)
    {
        return $this->CoreW->service($alias);
    }

    protected function createDao($alias)
    {
        return $this->CoreW->service($alias);
    }
}