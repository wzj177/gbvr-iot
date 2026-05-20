<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace support;

use CoreW\Webman\UploadFile;
use support\utils\DeviceToolkit;

/**
 * Class Request
 * @package support
 */
class Request extends \Webman\Http\Request
{
    /**
     * 是否是H5端
     * @return bool
     */
    public function isH5()
    {
        return DeviceToolkit::isMobileClient();
    }

    /**
     * 是否是微信端
     * @return bool
     */
    public function isWechat()
    {
        return DeviceToolkit::getClient($this->header('user-agent', '')) === 'wechat';
    }

    /**
     * 是否是小程序端
     * @return bool
     */
    public function isRoutine()
    {
        return DeviceToolkit::getClient($this->header('user-agent', '')) === 'routine';
    }

    /**
     * 是否是app端
     * @return bool
     */
    public function isApp()
    {
        return in_array(DeviceToolkit::getClient($this->header('user-agent', '')), ['ios', 'android']);
    }

    /**
     * 是否是app端
     * @return bool
     */
    public function isPc()
    {
        return DeviceToolkit::isPcClient($this->header('user-agent', ''));
    }


    protected function parseFile(array $file) : UploadFile
    {
        return new UploadFile($file['tmp_name'], $file['name'], $file['type'], $file['error']);
    }
}