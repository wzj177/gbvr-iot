<?php


namespace CoreW\Business\SystemLog;


use CoreW\Traits\EnumTrait;

class LogEnum
{
    use EnumTrait;

    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    public static function getLevelItems()
    {
        $items = [
            self::LEVEL_INFO    => '提示',
            self::LEVEL_WARNING => '警告',
            self::LEVEL_ERROR   => '错误',
        ];

        return $items;
    }

    const MODULE_ADMIN = 'admin';
    const MODULE_VIP = 'vip';
    const MODULE_ATTACHMENT = 'attachment';
    const MODULE_PRODUCT = 'product';
    const MODULE_PRODUCT_CATALOG = 'product_catalog';
    const MODULE_PRODUCT_TAG = 'product_tag';
    const MODULE_PRODUCT_SCENE = 'product_scene';
    const MODULE_SYSTEM = 'system';
    const MODULE_GB28181 = 'gb28181';
    const MODULE_SUBSCRIBE = 'subscribe';
    const MODULE_RECORD = 'record';
    const MODULE_RECORD_FILE = 'record_file';
    const MODULE_ROLE = 'role';
    const MODULE_USER = 'user';
    const MODULE_MEDIA_SERVER = 'media_server';
    const MODULE_MENU = 'menu';

    public static function getModuleItems()
    {
        $items = [
            self::MODULE_ADMIN           => '系统管理员',
            self::MODULE_VIP             => '会员',
            self::MODULE_ATTACHMENT      => '附件',
            self::MODULE_PRODUCT         => '作品',
            self::MODULE_PRODUCT_CATALOG => '作品分类',
            self::MODULE_PRODUCT_SCENE   => '作品场景',
            self::MODULE_PRODUCT_TAG     => '作品标签',
            self::MODULE_SYSTEM          => '系统',
            self::MODULE_GB28181         => 'GB28181设备',
            self::MODULE_SUBSCRIBE       => '订阅管理',
            self::MODULE_RECORD          => '录像',
            self::MODULE_RECORD_FILE     => '录像文件',
            self::MODULE_ROLE            => '角色',
            self::MODULE_USER            => '用户',
            self::MODULE_MEDIA_SERVER    => '媒体服务器',
            self::MODULE_MENU            => '菜单',
        ];

        return $items;
    }

    public static function getModuleText($module)
    {
        return self::getValue(self::getModuleItems(), $module, '其它');
    }

    const ACTION_ADD = 'add';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_DELETE_TAGS = 'delete_tags';
    const ACTION_UPDATE_SETTINGS = 'update_settings';
    const ACTION_UPLOAD = 'upload';
    const ACTION_LOGIN_SUCCESS = 'login_success';
    const ACTION_USER_LOGOUT = 'user_logout';
    const ACTION_VR_SCENE_CHUNK_PANORAMA = 'chunk_panorama';
    const ACTION_BATCH_UPDATE_AREA = 'batch_update_area';
    const ACTION_DELETE_DEVICE = 'delete_device';
    const ACTION_BATCH_BIND_MEDIA = 'batch_bind_media';
    const ACTION_QUERY_CATALOG = 'query_catalog';
    const ACTION_QUERY_RECORD = 'query_record';

    // Record actions
    const ACTION_EXECUTE_TASK = 'execute_task';
    const ACTION_INVITE_SENT = 'invite_sent';
    const ACTION_MEDIA_READY = 'media_ready';
    const ACTION_START_RECORDING = 'start_recording';
    const ACTION_STOP_RECORDING = 'stop_recording';
    const ACTION_COMPLETE_RECORDING = 'complete_recording';
    const ACTION_CANCEL_TASK = 'cancel_task';
    const ACTION_COMPLETE_FROM_HOOK = 'complete_from_hook';

    // RecordFile actions
    const ACTION_CREATE_FROM_HOOK = 'create_from_hook';

    // Product actions
    const ACTION_ADD_SCENE = 'add_scene';
    const ACTION_CLOSE = 'close';
    const ACTION_PUBLISH = 'publish';
    const ACTION_SET_PRODUCT_TOUR_NODES = 'set_product_tour_nodes';
    const ACTION_CREATE_PLANE_GRAPH_MARKERS = 'create_plane_graph_markers';

    // VIP actions
    const ACTION_SEND_EMAIL_LOGIN_CODE = 'send_email_login_code';
    const ACTION_EMAIL_VERIFY_NOTIFICATION = 'email_verify_notification';
    const ACTION_ADD_VIP = 'add_vip';
    const ACTION_EDIT_VIP_INFO = 'edit_vip_info';
    const ACTION_UNBIND = 'unbind';

    // User actions
    const ACTION_LOCK = 'lock';

    // Role actions
    const ACTION_INIT_CREATE_ROLE = 'init_create_role';

    // System/MediaServer actions
    const ACTION_RESTART_MEDIA_SERVER = 'restart_media_server';
    const ACTION_GET_MEDIA_SERVER_STATS = 'get_media_server_stats';
    const ACTION_CREATE_MEDIA_SERVER = 'create_media_server';
    const ACTION_SET_MEDIA_SERVER_CONFIG = 'set_media_server_config';
    const ACTION_GET_MEDIA_SERVER_CONFIG = 'get_media_server_config';
    const ACTION_UPDATE_MEDIA_SERVER = 'update_media_server';
    const ACTION_DELETE_MEDIA_SERVER = 'delete_media_server';
    const ACTION_GET_MEDIA_SERVER = 'get_media_server';

    // GB28181 actions
    const ACTION_GB_SERVER_HOOK = 'gb_server_hook';
    const ACTION_DEVICE_REGISTER = 'device_register';
    const ACTION_DEVICE_UNREGISTER = 'device_unregister';
    const ACTION_DEVICE_HEARTBEAT_EXPIRED = 'device_heartbeat_expired';
    const ACTION_DEVICE_OFFLINE = 'device_offline';
    const ACTION_DEVICE_HEARTBEAT = 'device_heartbeat';
    const ACTION_VOICE_INVITE = 'voice_invite';
    const ACTION_SESSION_BYE = 'session_bye';
    const ACTION_CLOSE_STREAM = 'close_stream';
    const ACTION_CLOSE_RTP_PORT = 'close_rtp_port';
    const ACTION_DEVICE_STATUS_CHANGED = 'device_status_changed';
    const ACTION_DEVICE_INFO = 'device_info';
    const ACTION_DEVICE_ALARM = 'device_alarm';
    const ACTION_COMMAND_CONFIRMED = 'command_confirmed';
    const ACTION_ALARM_EVENT = 'alarm_event';
    const ACTION_POSITION_UPDATE = 'position_update';

    // Subscribe actions
    const ACTION_UPDATE_SUBSCRIBE = 'update_subscribe';
    const ACTION_BATCH_CREATE_SUBSCRIBE_CONFIGS = 'batch_create_subscribe_configs';
    const ACTION_APPLY_SUBSCRIBE_CONFIG = 'apply_subscribe_config';
    const ACTION_CANCEL_SUBSCRIBE = 'cancel_subscribe';
    const ACTION_CATALOG_SUBSCRIBE = 'catalog_subscribe';
    const ACTION_ALARM_SUBSCRIBE = 'alarm_subscribe';
    const ACTION_MOBILE_POSITION_SUBSCRIBE = 'mobile_position_subscribe';
    const ACTION_MOBILE_POSITION_UNSUBSCRIBE = 'mobile_position_unsubscribe';
    const ACTION_RENEW_SUBSCRIPTION = 'renew_subscription';

    // Channel actions
    const ACTION_UPDATE_CHANNEL = 'update_channel';
    const ACTION_DELETE_CHANNEL = 'delete_channel';
    const ACTION_GET_URL_CODEC_INFO = 'get_url_codec_info';

    // Record actions
    const ACTION_ON_RECORD_TS = 'on_record_ts';

    // System/ZLM Hook actions
    const ACTION_ON_FLOW_REPORT = 'on_flow_report';
    const ACTION_ON_HTTP_ACCESS = 'on_http_access';
    const ACTION_ON_PLAY = 'on_play';
    const ACTION_ON_PUBLISH = 'on_publish';
    const ACTION_ON_RTSP_AUTH = 'on_rtsp_auth';
    const ACTION_ON_RTSP_REALM = 'on_rtsp_realm';
    const ACTION_ON_SERVER_STARTED = 'on_server_started';
    const ACTION_ON_SHELL_LOGIN = 'on_shell_login';
    const ACTION_ON_STREAM_CHANGED = 'on_stream_changed';
    const ACTION_ON_STREAM_NONE_READER = 'on_stream_none_reader';
    const ACTION_ON_STREAM_NOT_FOUND = 'on_stream_not_found';
    const ACTION_ON_STREAM_NOT_FOUND_FFMPEG = 'on_stream_not_found_ffmpeg';
    const ACTION_ON_RTP_SERVER_TIMEOUT = 'on_rtp_server_timeout';

    // Queue job actions
    const ACTION_SYNC_MEDIA_SERVER_STATUS = 'sync_media_server_status';
    const ACTION_UPDATE_RECORD_START_TIME = 'update_record_start_time';
    const ACTION_CREATE_FROM_HOOK_FAILED = 'create_from_hook_failed';
    const ACTION_DELETE_SCENE_AFTER = 'delete_scene_after';

    // voice_talk
    const ACTION_VOICE_TALK = 'voice_talk';

    // ZLM Hook additional actions
    const ACTION_ON_UNPUBLISH = 'on_unpublish';
    const ACTION_ON_SERVER_KEEPALIVE = 'on_server_keepalive';

    // GB28181 additional actions
    const ACTION_GATEWAY_CMD_AFTER = 'gateway_cmd_after';
    const ACTION_UNKNOWN_HOOK_SCENE = 'unknown_hook_scene';
    const ACTION_CLOSE_STREAM_SUCCESS = 'close_stream_success';
    const ACTION_CLOSE_RTP_PORT_SUCCESS = 'close_rtp_port_success';
    const ACTION_CLEAN_SESSION_COMPLETE = 'clean_session_complete';
    const ACTION_VOICE_PUBLISH_AUTH = 'voice_publish_auth';
    const ACTION_VOICE_PUBLISH_FAILED = 'voice_publish_failed';
    const ACTION_VOICE_UNPUBLISH_FAILED = 'voice_unpublish_failed';
    const ACTION_VOICE_STREAM_ARRIVAL = 'voice_stream_arrival';
    const ACTION_VOICE_STREAM_DEPARTURE = 'voice_stream_departure';
    const ACTION_VOICE_STREAM_CHANGED_FAILED = 'voice_stream_changed_failed';
    const ACTION_VOICE_NONE_READER = 'voice_none_reader';
    const ACTION_VOICE_NONE_READER_FAILED = 'voice_none_reader_failed';

    // User actions
    const ACTION_CREATE_USER = 'create_user';
    const ACTION_UPDATE_USER = 'update_user';
    const ACTION_DELETE_USER = 'delete_user';
    const ACTION_RESET_PASSWORD = 'reset_password';
    const ACTION_TOGGLE_LOCK = 'toggle_lock';
    const ACTION_BATCH_DELETE_USER = 'batch_delete_user';

    // Menu actions
    const ACTION_CREATE_MENU = 'create_menu';
    const ACTION_UPDATE_MENU = 'update_menu';
    const ACTION_DELETE_MENU = 'delete_menu';
    const ACTION_SYNC_MENU = 'sync_menu';
    const ACTION_BATCH_DELETE_MENU = 'batch_delete_menu';

    // Role actions
    const ACTION_CREATE_ROLE = 'create_role';
    const ACTION_UPDATE_ROLE = 'update_role';
    const ACTION_DELETE_ROLE = 'delete_role';
    const ACTION_BATCH_DELETE_ROLE = 'batch_delete_role';

    // Product actions
    const ACTION_DELETE_PRODUCT = 'delete_product';

    // ProductCatalog actions
    const ACTION_ADD_CATALOG = 'add_catalog';
    const ACTION_UPDATE_CATALOG = 'update_catalog';

    // Attachment actions
    const ACTION_DELETE_ATTACHMENT = 'delete_attachment';

    // Subscribe actions
    const ACTION_SUBSCRIBE_RESPONSE = 'subscribe_response';

    public static function getActionItems() : array
    {
        return [
            self::MODULE_ADMIN           => [
                self::ACTION_ADD             => '新增',
                self::ACTION_UPDATE          => '更新',
                self::ACTION_DELETE          => '删除',
                self::ACTION_UPDATE_SETTINGS => '更新配置',
                self::ACTION_UPLOAD          => '上传',
                self::ACTION_LOGIN_SUCCESS   => '登录成功',
                self::ACTION_USER_LOGOUT     => '登出成功',
            ],
            self::MODULE_ATTACHMENT      => [
                self::ACTION_UPLOAD            => '上传',
                self::ACTION_DELETE            => '删除',
                self::ACTION_DELETE_ATTACHMENT => '删除附件',
            ],
            self::MODULE_PRODUCT         => [
                self::ACTION_ADD                        => '新增',
                self::ACTION_UPDATE                     => '更新',
                self::ACTION_DELETE                     => '删除',
                self::ACTION_DELETE_PRODUCT             => '删除作品',
                self::ACTION_ADD_SCENE                  => '添加场景',
                self::ACTION_CLOSE                      => '关闭',
                self::ACTION_PUBLISH                    => '发布',
                self::ACTION_SET_PRODUCT_TOUR_NODES     => '设置导游节点',
                self::ACTION_CREATE_PLANE_GRAPH_MARKERS => '创建平面图标记',
            ],
            self::MODULE_PRODUCT_CATALOG => [
                self::ACTION_ADD            => '新增',
                self::ACTION_ADD_CATALOG    => '添加作品分类',
                self::ACTION_UPDATE         => '更新',
                self::ACTION_UPDATE_CATALOG => '更新作品分类',
                self::ACTION_DELETE         => '删除',
            ],
            self::MODULE_PRODUCT_TAG     => [
                self::ACTION_ADD         => '新增',
                self::ACTION_UPDATE      => '更新',
                self::ACTION_DELETE      => '删除',
                self::ACTION_DELETE_TAGS => '批量删除',
            ],
            self::MODULE_PRODUCT_SCENE   => [
                self::ACTION_ADD                     => '新增',
                self::ACTION_UPDATE                  => '更新',
                self::ACTION_DELETE                  => '删除',
                self::ACTION_VR_SCENE_CHUNK_PANORAMA => '场景图切片',
                self::ACTION_DELETE_SCENE_AFTER      => '清理场景资源文件',
            ],
            self::MODULE_SYSTEM          => [
                self::ACTION_UPDATE_SETTINGS => '更新配置',
            ],
            self::MODULE_VIP             => [
                self::ACTION_ADD                       => '新增',
                self::ACTION_ADD_VIP                   => '新增会员',
                self::ACTION_UPDATE                    => '更新',
                self::ACTION_EDIT_VIP_INFO             => '编辑会员信息',
                self::ACTION_DELETE                    => '删除',
                self::ACTION_SEND_EMAIL_LOGIN_CODE     => '发送邮箱登录验证码',
                self::ACTION_EMAIL_VERIFY_NOTIFICATION => '邮箱验证通知',
                self::ACTION_UNBIND                    => '解绑',
            ],
            self::MODULE_USER            => [
                self::ACTION_ADD               => '新增',
                self::ACTION_CREATE_USER       => '创建用户',
                self::ACTION_UPDATE            => '更新',
                self::ACTION_UPDATE_USER       => '更新用户',
                self::ACTION_DELETE            => '删除',
                self::ACTION_DELETE_USER       => '删除用户',
                self::ACTION_RESET_PASSWORD    => '重置用户密码',
                self::ACTION_TOGGLE_LOCK       => '切换用户锁定状态',
                self::ACTION_BATCH_DELETE_USER => '批量删除用户',
                self::ACTION_LOCK              => '锁定用户',
                self::ACTION_UNBIND            => '解绑',
            ],
            self::MODULE_MENU            => [
                self::ACTION_ADD               => '新增',
                self::ACTION_CREATE_MENU       => '创建菜单',
                self::ACTION_UPDATE            => '更新',
                self::ACTION_UPDATE_MENU       => '更新菜单',
                self::ACTION_DELETE            => '删除',
                self::ACTION_DELETE_MENU       => '删除菜单',
                self::ACTION_SYNC_MENU         => '同步菜单',
                self::ACTION_BATCH_DELETE_MENU => '批量删除菜单',
            ],
            self::MODULE_ROLE            => [
                self::ACTION_ADD               => '新增',
                self::ACTION_CREATE_ROLE       => '创建角色',
                self::ACTION_UPDATE            => '更新',
                self::ACTION_UPDATE_ROLE       => '更新角色',
                self::ACTION_DELETE            => '删除',
                self::ACTION_DELETE_ROLE       => '删除角色',
                self::ACTION_BATCH_DELETE_ROLE => '批量删除角色',
                self::ACTION_INIT_CREATE_ROLE  => '初始化创建角色',
            ],
            self::MODULE_GB28181         => [
                self::ACTION_UPDATE                      => '更新设备',
                self::ACTION_DELETE                      => '删除设备',
                self::ACTION_DELETE_DEVICE               => '删除设备',
                self::ACTION_BATCH_UPDATE_AREA           => '批量设置地区',
                self::ACTION_QUERY_CATALOG               => '查询目录',
                self::ACTION_QUERY_RECORD                => '查询录像',
                self::ACTION_BATCH_BIND_MEDIA            => '批量绑定媒体',
                self::ACTION_GB_SERVER_HOOK              => 'GB服务器钩子',
                self::ACTION_DEVICE_REGISTER             => '设备注册',
                self::ACTION_DEVICE_UNREGISTER           => '设备注销',
                self::ACTION_DEVICE_HEARTBEAT_EXPIRED    => '设备心跳过期',
                self::ACTION_DEVICE_OFFLINE              => '设备离线',
                self::ACTION_DEVICE_HEARTBEAT            => '设备心跳',
                self::ACTION_VOICE_INVITE                => '语音邀请',
                self::ACTION_VOICE_TALK                  => '语音对讲',
                self::ACTION_SESSION_BYE                 => '会话结束',
                self::ACTION_CLOSE_STREAM                => '关闭流',
                self::ACTION_CLOSE_RTP_PORT              => '关闭RTP端口',
                self::ACTION_DEVICE_STATUS_CHANGED       => '设备状态已改变',
                self::ACTION_DEVICE_INFO                 => '设备信息',
                self::ACTION_DEVICE_ALARM                => '设备报警',
                self::ACTION_COMMAND_CONFIRMED           => '命令已确认',
                self::ACTION_ALARM_EVENT                 => '报警事件',
                self::ACTION_POSITION_UPDATE             => '位置更新',
                self::ACTION_UPDATE_CHANNEL              => '更新通道',
                self::ACTION_DELETE_CHANNEL              => '删除通道',
                self::ACTION_GET_URL_CODEC_INFO          => '获取URL编解码信息',
                self::ACTION_GATEWAY_CMD_AFTER           => '网关命令执行完成',
                self::ACTION_UNKNOWN_HOOK_SCENE          => '未知的Hook场景',
                self::ACTION_CLOSE_STREAM_SUCCESS        => '关闭流成功',
                self::ACTION_CLOSE_RTP_PORT_SUCCESS      => '关闭RTP端口成功',
                self::ACTION_CLEAN_SESSION_COMPLETE      => '清理会话完成',
                self::ACTION_VOICE_PUBLISH_AUTH          => '语音对讲推流鉴权',
                self::ACTION_VOICE_PUBLISH_FAILED        => '语音对讲推流鉴权失败',
                self::ACTION_VOICE_UNPUBLISH_FAILED      => '语音对讲推流结束处理失败',
                self::ACTION_VOICE_STREAM_ARRIVAL        => '语音流到达',
                self::ACTION_VOICE_STREAM_DEPARTURE      => '语音流离开',
                self::ACTION_VOICE_STREAM_CHANGED_FAILED => '语音流变化处理失败',
                self::ACTION_VOICE_NONE_READER           => '语音对讲流无人使用',
                self::ACTION_VOICE_NONE_READER_FAILED    => '语音对讲流无人使用处理失败',
                self::ACTION_ON_RTP_SERVER_TIMEOUT       => 'RTP服务器超时',
            ],
            self::MODULE_SUBSCRIBE       => [
                self::ACTION_UPDATE_SUBSCRIBE               => '更新订阅配置',
                self::ACTION_BATCH_CREATE_SUBSCRIBE_CONFIGS => '批量创建订阅配置',
                self::ACTION_APPLY_SUBSCRIBE_CONFIG         => '应用订阅配置',
                self::ACTION_CANCEL_SUBSCRIBE               => '取消订阅',
                self::ACTION_CATALOG_SUBSCRIBE              => '目录订阅',
                self::ACTION_ALARM_SUBSCRIBE                => '报警订阅',
                self::ACTION_MOBILE_POSITION_SUBSCRIBE      => '移动位置订阅',
                self::ACTION_MOBILE_POSITION_UNSUBSCRIBE    => '取消移动位置订阅',
                self::ACTION_RENEW_SUBSCRIPTION             => '续订',
                self::ACTION_SUBSCRIBE_RESPONSE             => '订阅响应',
            ],
            self::MODULE_RECORD          => [
                self::ACTION_EXECUTE_TASK             => '执行录像任务',
                self::ACTION_INVITE_SENT              => '邀请已发送',
                self::ACTION_MEDIA_READY              => '媒体就绪',
                self::ACTION_START_RECORDING          => '开始录像',
                self::ACTION_STOP_RECORDING           => '停止录像',
                self::ACTION_COMPLETE_RECORDING       => '完成录像',
                self::ACTION_CANCEL_TASK              => '取消任务',
                self::ACTION_COMPLETE_FROM_HOOK       => '从钩子完成',
                self::ACTION_ON_RECORD_TS             => '录像TS文件',
                self::ACTION_UPDATE_RECORD_START_TIME => '更新录像开始时间',
            ],
            self::MODULE_RECORD_FILE     => [
                self::ACTION_CREATE_FROM_HOOK        => '从钩子创建',
                self::ACTION_CREATE_FROM_HOOK_FAILED => '从钩子创建失败',
            ],
            self::MODULE_MEDIA_SERVER    => [
                self::ACTION_RESTART_MEDIA_SERVER       => '重启媒体服务器',
                self::ACTION_GET_MEDIA_SERVER_STATS     => '获取媒体服务器统计',
                self::ACTION_CREATE_MEDIA_SERVER        => '创建媒体服务器',
                self::ACTION_SET_MEDIA_SERVER_CONFIG    => '设置媒体服务器配置',
                self::ACTION_GET_MEDIA_SERVER_CONFIG    => '获取媒体服务器配置',
                self::ACTION_UPDATE_MEDIA_SERVER        => '更新媒体服务器',
                self::ACTION_DELETE_MEDIA_SERVER        => '删除媒体服务器',
                self::ACTION_GET_MEDIA_SERVER           => '获取媒体服务器',
                self::ACTION_SYNC_MEDIA_SERVER_STATUS   => '同步媒体服务器状态',
                self::ACTION_ON_FLOW_REPORT             => '流量报告',
                self::ACTION_ON_HTTP_ACCESS             => 'HTTP访问',
                self::ACTION_ON_PLAY                    => '播放',
                self::ACTION_ON_PUBLISH                 => '推流',
                self::ACTION_ON_UNPUBLISH               => '推流结束',
                self::ACTION_ON_RTSP_AUTH               => 'RTSP认证',
                self::ACTION_ON_RTSP_REALM              => 'RTSP域',
                self::ACTION_ON_SERVER_STARTED          => '服务器已启动',
                self::ACTION_ON_SHELL_LOGIN             => 'Shell登录',
                self::ACTION_ON_STREAM_CHANGED          => '流已改变',
                self::ACTION_ON_STREAM_NONE_READER      => '流无读取者',
                self::ACTION_ON_STREAM_NOT_FOUND        => '流不存在',
                self::ACTION_ON_STREAM_NOT_FOUND_FFMPEG => 'FFmpeg流未找到',
                self::ACTION_ON_SERVER_KEEPALIVE        => '服务器心跳',
            ],
        ];
    }

    public static function getModuleActionItems($module)
    {
        $items = self::getActionItems();

        return $items[$module] ?? [];
    }

    /**
     * @param $module
     * @param $action
     * @return mixed|string
     */
    public static function getActionText($module, $action)
    {
        $actions = self::getActionItems();
        if (!isset($actions[$module][$action])) {
            return '其它';
        }

        return $actions[$module][$action];
    }

    /**
     * @param $level
     * @return bool|int|string|null
     */
    public static function getLevelText($level)
    {
        return self::getValue(self::getLevelItems(), $level, '其它');
    }
}