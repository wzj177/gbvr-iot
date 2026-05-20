<?php

namespace CoreW\Business;

use CoreW\Exception\InvalidParamException;
use CoreW\Traits\EnumTrait;

/**
 *
 * 基础枚举常量类
 *
 * Class Constants
 * @package CoreW\Business
 */
class BizEnum
{
    use EnumTrait;

    const VR_HOTPOINT_TYPE_TEXT = 'text';
    const VR_HOTPOINT_TYPE_VIDEO = 'video';
    const VR_HOTPOINT_TYPE_IMAGE = 'image';
    const VR_HOTPOINT_TYPE_LINK = 'hyperlink';
    const VR_HOTPOINT_TYPE_SCENE_CHANGE = 'sceneChange';
    const VR_HOTPOINT_TYPE_IOT = 'iot';

    public static function getVrHotpointTypeItems($key = null)
    {
        $items = [
            self::VR_HOTPOINT_TYPE_TEXT         => '文本热点',
            self::VR_HOTPOINT_TYPE_VIDEO        => '视频热点',
            self::VR_HOTPOINT_TYPE_IMAGE        => '图片热点',
            self::VR_HOTPOINT_TYPE_LINK         => '超链接',
            self::VR_HOTPOINT_TYPE_SCENE_CHANGE => '场景切换',
            self::VR_HOTPOINT_TYPE_IOT          => '设备热点',
        ];

        return self::getItems($items, $key);
    }

    const VIP_COMPANY_IOT_API_DEVICE_CATALOG = 'device_catalog';
    const VIP_COMPANY_IOT_API_DEVICE_LIST = 'device_list';
    const VIP_COMPANY_IOT_API_DEVICE_INFO = 'device_info';
    const VIP_COMPANY_IOT_API_DEVICE_REAL_DATA = 'device_real_data';
    const VIP_COMPANY_IOT_API_DEVICE_HISTORY_DATA = 'device_history_data';
    const VIP_COMPANY_IOT_API_CAMERA_LIVE_URL = 'camera_live_url';
    const VIP_COMPANY_IOT_API_GIS_TILES_URL = 'gis_tiles_url';

    const VIP_COMPANY_IOT_API_AUTH = 'auth';

    public static function getCompanyIotApiItems($key = null)
    {
        $items = [
            self::VIP_COMPANY_IOT_API_DEVICE_CATALOG      => '设备分类',
            self::VIP_COMPANY_IOT_API_DEVICE_LIST         => '设备列表',
            self::VIP_COMPANY_IOT_API_DEVICE_INFO         => '设备信息',
            self::VIP_COMPANY_IOT_API_DEVICE_REAL_DATA    => '设备实时数据',
            self::VIP_COMPANY_IOT_API_DEVICE_HISTORY_DATA => '设备历史数据',
            self::VIP_COMPANY_IOT_API_CAMERA_LIVE_URL     => '摄像头直播地址',
            self::VIP_COMPANY_IOT_API_GIS_TILES_URL       => 'GIS切片地址(基地鸟瞰图等)',
        ];
        return self::getItems($items, $key);
    }

    const VIP_COMPANY_STATUS_REJECT = -1;
    const VIP_COMPANY_STATUS_WAIT = 0;
    const VIP_COMPANY_STATUS_OK = 1;

    public static function getVipCompanyStatusItems($key = null)
    {
        $items = [
            self::VIP_COMPANY_STATUS_REJECT => '驳回',
            self::VIP_COMPANY_STATUS_WAIT   => '待审核',
            self::VIP_COMPANY_STATUS_OK     => '已通过',
        ];

        return self::getItems($items, $key);
    }

    const VIP_ROLE_PERSON = 0; // 个人
    const VIP_ROLE_COMPANY = 1; // 企业


    /**
     * 会员类型字典
     * @param $key
     * @return array|bool|int|string
     */
    public static function getVipRoleItems($key = null)
    {
        $items = [
            self::VIP_ROLE_PERSON  => '个人',
            self::VIP_ROLE_COMPANY => '企业',
        ];
        return self::getItems($items, $key);
    }

    const PRODUCT_SCENE_TILE_STATUS_NONE = 0;
    const PRODUCT_SCENE_TILE_STATUS_ING = 1;
    const PRODUCT_SCENE_TILE_STATUS_OK = 2;
    const PRODUCT_SCENE_TILE_STATUS_ERR = -1;

    public static function getProductSceneTileStatusItems($key = null)
    {
        $items = [
            self::PRODUCT_SCENE_TILE_STATUS_NONE => '未生成',
            self::PRODUCT_SCENE_TILE_STATUS_ING  => '生成中',
            self::PRODUCT_SCENE_TILE_STATUS_OK   => '已生成',
            self::PRODUCT_SCENE_TILE_STATUS_ERR  => '生成失败',
        ];

        return self::getItems($items, $key);
    }

    const PRODUCT_STATUS_PUBLISHED = 'published';
    const PRODUCT_STATUS_CLOSED = 'closed';
    const PRODUCT_STATUS_DRAFTED = 'drafted';

    public static function getProductStatusItems($key = null)
    {
        $items = [
            self::PRODUCT_STATUS_PUBLISHED => '已发布',
            self::PRODUCT_STATUS_CLOSED    => '已关闭',
            self::PRODUCT_STATUS_DRAFTED   => '草稿',
        ];

        return self::getItems($items, $key);
    }


    const PRODUCT_TYPE_PICTURES = 'pictures';

    const PRODUCT_TYPE_VIDEOS = 'videos';

    const PRODUCT_TYPE_3D_RING = '3d_ring';

    /**
     * 作品类型字典
     *
     * @param $key
     * @return array|bool|int|string
     */
    public static function getProductTypeItems($key = null)
    {
        $items = [
            self::PRODUCT_TYPE_PICTURES => '图片全景',
            self::PRODUCT_TYPE_VIDEOS   => '视频全景',
            self::PRODUCT_TYPE_3D_RING  => '3D环物全景',
        ];

        return self::getItems($items, $key);
    }

    const OAUTH_CLIENT_QQ = 'qq';
    const OAUTH_CLIENT_WECHAT_WEB = 'wechat_web';
    const OAUTH_CLIENT_WECHAT_MOB = 'wechat_mo';
    const USER_GENDER_MALE = 'male';
    const USER_GENDER_FEMALE = 'female';
    const USER_GENDER_SECRET = 'secret';

    public static function getUserGenderItems($key = null)
    {
        $items = [
            self::USER_GENDER_MALE   => '男',
            self::USER_GENDER_FEMALE => '女',
            self::USER_GENDER_SECRET => '保密',
        ];

        return self::getItems($items, $key);
    }

    const PRODUCT_TAG_TYPE_SYSTEM = 'system';
    const PRODUCT_TAG_TYPE_CUSTOM = 'custom';

    /**
     * @param null $key
     * @return array|bool|int|string
     */
    public static function getProductTagTypeItems($key = null)
    {
        $items = [
            self::PRODUCT_TAG_TYPE_SYSTEM => '系统内置',
            self::PRODUCT_TAG_TYPE_CUSTOM => '用户自定义',
        ];

        return self::getItems($items, $key);
    }


    const FILE_TYPE_IMAGE = 'image';
    const FILE_TYPE_DOCUMENT = 'document';
    const FILE_TYPE_VIDEO = 'video';
    const FILE_TYPE_AUDIO = 'audio';
    const FILE_TYPE_PPT = 'ppt';
    const FILE_TYPE_OTHER = 'other';

    public static function getFileTypeItems($key = null)
    {
        $items = [
            self::FILE_TYPE_IMAGE    => '图片',
            self::FILE_TYPE_DOCUMENT => '文档',
            self::FILE_TYPE_VIDEO    => '视频',
            self::FILE_TYPE_AUDIO    => '音频',
            self::FILE_TYPE_PPT      => 'PPT',
            self::FILE_TYPE_OTHER    => '其它',
        ];

        return self::getItems($items, $key);
    }

    const UPLOAD_CLIENT_BACKEND = 'backend';
    const UPLOAD_CLIENT_FRONTEND = 'frontend';
    const UPLOAD_CLIENT_MIDDLE = 'middle';

    public static function getUploadClientTypeItems($key = null)
    {
        $items = [
            self::UPLOAD_CLIENT_BACKEND  => '后台',
            self::UPLOAD_CLIENT_FRONTEND => '前台',
            self::UPLOAD_CLIENT_MIDDLE   => '中台',
        ];

        return self::getItems($items, $key);
    }


    const STORAGE_TYPE_LOCAL = 'local';
    const STORAGE_TYPE_QINIU = 'qiniu';
    const STORAGE_TYPE_ALI = 'ali';
    const STORAGE_TYPE_TENCENT = 'tencent';

    public static function getStorageTypeItems($key = null)
    {
        $items = [
            self::STORAGE_TYPE_LOCAL   => '本地存储',
            self::STORAGE_TYPE_QINIU   => '七牛云oss',
            self::STORAGE_TYPE_ALI     => '阿里云oss',
            self::STORAGE_TYPE_TENCENT => '腾讯云oss',
        ];

        return self::getItems($items, $key);
    }

    const USER_PASSWORD_LEVEL_LOWER = 'lower';
    const USER_PASSWORD_LEVEL_MIDDLE = 'middle';
    const USER_PASSWORD_LEVEL_HIGH = 'high';

    public static function getUserPwdLevelItems($key = null)
    {
        $items = [
            self::USER_PASSWORD_LEVEL_LOWER  => '低',
            self::USER_PASSWORD_LEVEL_MIDDLE => '中',
            self::USER_PASSWORD_LEVEL_HIGH   => '高',
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
    const TOKEN_TYPE_WECHAT_LOGIN = 'wechat_login';
    const TOKEN_TYPE_APP_LOGIN = 'app_login';
    const TOKEN_TYPE_VIP_PC_LOGIN = 'api_login';

    /**
     * get token type
     *
     * @param [type] $key
     * @return void
     */
    public static function getLoginTypeItems($key = null)
    {
        $items = [
            self::TOKEN_TYPE_ADMIN_LOGIN  => '后台登录',
            self::TOKEN_TYPE_H5_LOGIN     => 'H5登录',
            self::TOKEN_TYPE_WECHAT_LOGIN => '微信登录',
            self::TOKEN_TYPE_APP_LOGIN    => '手机app登录',
            self::TOKEN_TYPE_VIP_PC_LOGIN => '会员登录',
        ];

        return self::getItems($items, $key);
    }

    const ATTACHMENT_IMAGE_CLIP_TYPE_SMALL = 'small';
    const ATTACHMENT_IMAGE_CLIP_TYPE_MEDIUM = 'medium';
    const ATTACHMENT_IMAGE_CLIP_TYPE_LARGE = 'large';

    public static function getAttachmentImageClipTypeItems($key = null)
    {
        $items = [
            self::ATTACHMENT_IMAGE_CLIP_TYPE_SMALL  => '小',
            self::ATTACHMENT_IMAGE_CLIP_TYPE_MEDIUM => '中',
            self::ATTACHMENT_IMAGE_CLIP_TYPE_LARGE  => '大',
        ];
        return self::getItems($items, $key);
    }

    const ATTACHMENT_IMAGE_TYPE_JPEG = 'jpeg';
    const ATTACHMENT_IMAGE_TYPE_JPG = 'jpg';
    const ATTACHMENT_IMAGE_TYPE_PNG = 'png';
    const ATTACHMENT_IMAGE_TYPE_GIF = 'gif';
    const ATTACHMENT_IMAGE_TYPE_ICO = 'ico';

    public static function getAttachmentImageTypeItems($key = null)
    {
        $items = [
            self::ATTACHMENT_IMAGE_TYPE_JPEG => 'JPEG',
            self::ATTACHMENT_IMAGE_TYPE_JPG  => 'JPG',
            self::ATTACHMENT_IMAGE_TYPE_PNG  => 'PNG',
            self::ATTACHMENT_IMAGE_TYPE_GIF  => 'GIF',
            self::ATTACHMENT_IMAGE_TYPE_ICO  => 'ICO',
        ];
        return self::getItems($items, $key);
    }

    const ATTACHMENT_AUDIO_TYPE_MP3 = 'mp3';

    public static function getAttachmentAudioTypeItems($key = null)
    {
        $items = [
            self::ATTACHMENT_AUDIO_TYPE_MP3 => 'MP3',
        ];
        return self::getItems($items, $key);
    }

    const ATTACHMENT_VIDEO_TYPE_MP4 = 'mp4';
    const ATTACHMENT_VIDEO_TYPE_MOV = 'mov';

    public static function getAttachmentVideoTypeItems($key = null)
    {
        $items = [
            self::ATTACHMENT_VIDEO_TYPE_MP4 => 'MP4',
            self::ATTACHMENT_VIDEO_TYPE_MOV => 'MOV',
        ];
        return self::getItems($items, $key);
    }


}