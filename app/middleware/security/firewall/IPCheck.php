<?php


namespace app\middleware\security\firewall;


use CoreW\Bfw;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Core;
use Webman\Http\Request;
use Webman\Http\Response;

class IPCheck
{
    protected $biz;

    public function __construct(Bfw $biz)
    {
        $this->biz = $biz;
    }

    public function validate(Request $request)
    {
        $blacklistIps = $this->getSettingService()->get('blacklist_ip');
        $whitelistIps = $this->getSettingService()->get('whitelist_ip');
        $clientIp = $request->getRealIp();
        if (!empty($blacklistIps)) {
            if ($this->matchIpConfigList($clientIp, $blacklistIps)) {
                throw CommonBizException::USER_IP_FORBIDDEN();
            }
        }

        if (!empty($whitelistIps)) {
            if (!$this->matchIpConfigList($clientIp, $whitelistIps)) {
                throw CommonBizException::USER_IP_FORBIDDEN();
            }
        }

        return true;
    }

    private function matchIpConfigList($clientIp, $ipConfigList)
    {
        foreach ($ipConfigList as $ipConfigEntry) {
            if ($this->matchIp($clientIp, $ipConfigEntry)) {
                return true;
            }
        }
        return false;
    }

    private function matchIp($clientIp, $ipConfigEntry)
    {
        $ipConfigEntry = trim($ipConfigEntry);
        if (strlen($ipConfigEntry) > 0) {
            $regex = str_replace('.', "\.", $ipConfigEntry);
            $regex = str_replace('*', "\d{1,3}", $regex);
            $regex = '/^' . $regex . '/';
            return preg_match($regex, $clientIp);
        } else {
            return false;
        }
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->biz->service('Setting:SettingService');
    }
}