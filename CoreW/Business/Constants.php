<?php

namespace CoreW\Business;

use CoreW\Exception\InvalidParamException;

class Constants
{
    const IOT_DEVICE_USED_STATUS_YES = 1;
    const IOT_DEVICE_USED_STATUS_NO = 0;

    public static function getIotDeviceUsedStatusItems($key = null)
    {
        $items = [
            self::IOT_DEVICE_USED_STATUS_YES => '已使用',
            self::IOT_DEVICE_USED_STATUS_NO  => '未使用',
        ];
        return self::getItems($items, $key);
    }

    const IOT_DEVICE_TYPE_MOTORCYCLE = 'motorcycle';

    public static function getIotDeviceTypeItems($key = null)
    {
        $items = [
            self::IOT_DEVICE_TYPE_MOTORCYCLE => '共享电单车',
        ];
        return self::getItems($items, $key);
    }

    const IOT_DEVICE_STATUS_DISABLED = -1;
    const IOT_DEVICE_STATUS_UNACTIVATED = 0;
    const IOT_DEVICE_STATUS_ENABLED = 1;

    public static function getIotDeviceStatusItems($key = null)
    {
        $items = [
            self::IOT_DEVICE_STATUS_DISABLED    => '禁用',
            self::IOT_DEVICE_STATUS_UNACTIVATED => '未激活',
            self::IOT_DEVICE_STATUS_ENABLED     => '正常',
        ];
        return self::getItems($items, $key);
    }

    //连接状态,-1=离线;0=未连接;1=在线
    const IOT_DEVICE_CONN_STATUS_OFFLINE = -1;
    const IOT_DEVICE_CONN_STATUS_NONE = 0;
    const IOT_DEVICE_CONN_STATUS_ONLINE = 1;

    public static function getIotDeviceConnStatusItems($key = null)
    {
        $items = [
            self::IOT_DEVICE_CONN_STATUS_OFFLINE => '离线',
            self::IOT_DEVICE_CONN_STATUS_NONE    => '未连接',
            self::IOT_DEVICE_CONN_STATUS_ONLINE  => '在线',
        ];
        return self::getItems($items, $key);
    }

    const VIDEO_CHANNEL_RECORD_STATUS_NONE = 0;
    const VIDEO_CHANNEL_RECORD_STATUS_ING = 1;
    const VIDEO_CHANNEL_RECORD_STATUS_CLOSE = -1;

    public static function getVideoChannelRecordStatusItems($key = null)
    {
        $items = [
            self::VIDEO_CHANNEL_RECORD_STATUS_NONE  => '未录像',
            self::VIDEO_CHANNEL_RECORD_STATUS_ING   => '录像中',
            self::VIDEO_CHANNEL_RECORD_STATUS_CLOSE => '关闭录像',
        ];
        return self::getItems($items, $key);
    }

    const YES = 1;
    const NO = 2;

    public static function getYesOrNoItems($key = null)
    {
        $items = [
            self::YES => '是',
            self::NO  => '否',
        ];

        return self::getItems($items, $key);
    }

    const ENABLED = 1;
    const DISABLED = 0;

    public static function getEnableOrDisableItems($key = null)
    {
        $items = [
            self::ENABLED  => '启用',
            self::DISABLED => '禁用',
        ];

        return self::getItems($items, $key);
    }

    const TOKEN_TYPE_ADMIN_LOGIN = 'admin_login';
    const TOKEN_TYPE_H5_LOGIN = 'h5_login';
    const TOKEN_TYPE_MP_WEIXIN_LOGIN = 'mp_weixin_login';
    const TOKEN_TYPE_APP_LOGIN = 'app_login';

    /**
     * get token type
     *
     * @param [type] $key
     * @return void
     */
    public static function getLoginTypeItems($key = null)
    {
        $items = [
            self::TOKEN_TYPE_ADMIN_LOGIN     => '后台登录',
            self::TOKEN_TYPE_H5_LOGIN        => 'H5登录',
            self::TOKEN_TYPE_MP_WEIXIN_LOGIN => '微信小程序登录',
            self::TOKEN_TYPE_APP_LOGIN       => '手机app登录',
        ];

        return self::getItems($items, $key);
    }

    const LIVE_PROVIDER_OPEN_YS7 = 'Ys7';
    const LIVE_PROVIDER_OPEN_ISECURE_CENTER = 'ISecureCenter';
    const LIVE_PROVIDER_OPEN_PRIVATE_GB28181 = 'PrivateGb28181';

    const LIVE_PROVIDER_OPEN_LE_CHANGE = 'LeChange';
    const LIVE_PROVIDER_OPEN_LE_YS7_CHANGE = 'YS7LeChange';

    public static function getLiveProviderItems($key = null)
    {
        $items = [
            self::LIVE_PROVIDER_OPEN_YS7             => '萤石云',
            self::LIVE_PROVIDER_OPEN_LE_CHANGE       => '乐橙',
            self::LIVE_PROVIDER_OPEN_LE_YS7_CHANGE   => '萤石云+乐橙',
            self::LIVE_PROVIDER_OPEN_ISECURE_CENTER  => '综合安防管理平台',
            self::LIVE_PROVIDER_OPEN_PRIVATE_GB28181 => '国标-私有云',
        ];

        return self::getItems($items, $key);
    }

    const BLIVE_STREAM_PROTOCOL_HLS = 'hls';
    const BLIVE_STREAM_PROTOCOL_WS_HLS = 'ws_fls';
    const BLIVE_STREAM_PROTOCOL_FLV = 'http_flv';
    const BLIVE_STREAM_PROTOCOL_WS_FLV = 'ws_flv';
    const BLIVE_STREAM_PROTOCOL_MP4 = 'mp4';
    const BLIVE_STREAM_PROTOCOL_WS_MP4 = 'ws_mp4';
    const BLIVE_STREAM_PROTOCOL_RTSP = 'rtsp';
    const BLIVE_STREAM_PROTOCOL_RTMP = 'rtmp';

    public static function getBLiveStreamProtocolItems($key = null)
    {
        $items = [
            self::BLIVE_STREAM_PROTOCOL_HLS    => 'HLS',
            self::BLIVE_STREAM_PROTOCOL_WS_HLS => 'WS HLS',
            self::BLIVE_STREAM_PROTOCOL_FLV    => 'HTTP FLV',
            self::BLIVE_STREAM_PROTOCOL_WS_FLV => 'WS FLV',
            self::BLIVE_STREAM_PROTOCOL_MP4    => 'MP4',
            self::BLIVE_STREAM_PROTOCOL_WS_MP4 => 'WS MP4',
            self::BLIVE_STREAM_PROTOCOL_RTSP   => 'RTSP',
            self::BLIVE_STREAM_PROTOCOL_RTMP   => 'RTMP',
        ];

        return self::getItems($items, $key);
    }


    const DEVICE_STATUS_UNKNOWN = 0;
    const DEVICE_STATUS_OFFLINE = -1;
    const DEVICE_STATUS_ONLINE = 1;

    /**
     * 设备状态
     *
     * @param [type] $key
     * @return void|string|array[]
     */
    public static function getDeviceStatusItems($key = null)
    {
        $items = [
            self::DEVICE_STATUS_UNKNOWN => '未知',
            self::DEVICE_STATUS_OFFLINE => '离线',
            self::DEVICE_STATUS_ONLINE  => '在线',
        ];

        return self::getItems($items, $key);
    }

    const DEVICE_ACTIVE = 1;
    const DEVICE_UN_ACTIVE = 0;

    /**
     * 设备激活状态
     *
     * @param [type] $key
     * @return void
     */
    public static function getDeviceEnableStatusItems($key = null)
    {
        $items = [
            self::DEVICE_ACTIVE    => '已激活',
            self::DEVICE_UN_ACTIVE => '未激活',
        ];

        return self::getItems($items, $key);
    }

    const DEVICE_PUSH_REGISTERED = 'registered';
    const DEVICE_PUSH_UN_REGISTERED = 'unRegistered';

    public static function getDevicePushStatusItems($key = null)
    {
        $items = [
            self::DEVICE_PUSH_REGISTERED    => '注册上报',
            self::DEVICE_PUSH_UN_REGISTERED => '注销上报',
        ];

        return self::getItems($items, $key);
    }

    const RECORD_PLAN_OVER_STEP_DEL_FILE = 'delFile';
    const RECORD_PLAN_OVER_STEP_STOP_DVR = 'stopDvr';


    public static function getRecordePlanOverStepTypes($key = null)
    {
        $items = [
            self::RECORD_PLAN_OVER_STEP_DEL_FILE => '删除文件',
            self::RECORD_PLAN_OVER_STEP_STOP_DVR => '停止录制',
        ];

        return self::getItems($items, $key);
    }


    /**
     * @param array $items
     * @param string|null $key
     * @return array|string|int|bool
     * @throws InvalidParamException
     */
    private static function getItems(array $items, $key = null)
    {
        if ($key !== null) {
            if (key_exists($key, $items)) {
                return $items[$key];
            }
            throw new InvalidParamException('Unknown key:' . $key);
        }

        return $items;
    }

    /**
     * @param array $items
     * @param string|integer|null $key
     * @param string $defaultValue
     * @return string|integer|null|bool
     */
    public static function getValue(array $items, $key = null, string $defaultValue = '')
    {
        return $items[$key] ? : $defaultValue;
    }
}