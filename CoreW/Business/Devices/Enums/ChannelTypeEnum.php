<?php

namespace CoreW\Business\Devices\Enums;

/**
 * GB/T 28181-2016 设备/通道类型枚举（严格按标准文档）
 * 类型码：DeviceID 第 11~13 位
 */
enum ChannelTypeEnum: int
{
    // =============== 前端主设备 (111 ~ 130) ===============
    case DVR = 111; // DVR 编码
    case VIDEO_SERVER = 112; // 视频服务器
    case ENCODER = 113; // 编码器
    case DECODER = 114; // 解码器
    case VIDEO_MATRIX = 115; // 视频切换矩阵
    case AUDIO_MATRIX = 116; // 音频切换矩阵
    case ALARM_CONTROLLER = 117; // 报警控制器
    case NVR = 118; // 网络视频录像机 (NVR)
    case ONLINE_VIDEO_SYSTEM = 120; // 在线视频图像信息采集系统
    case VIDEO_TOLLGATE = 121; // 视频卡口
    case MULTI_CAMERA = 122; // 多目设备
    case PARKING_GATE = 123; // 停车场出入口控制设备
    case PERSONNEL_GATE = 124; // 人员出入口控制设备
    case SECURITY_CHECK = 125; // 安检设备
    case HVR = 130; // 混合硬盘录像机 (HVR)

    // =============== 前端外围设备 (131 ~ 199) ===============
    case CAMERA = 131; // 摄像机
    case IPC = 132; // 网络摄像机 (IPC) / 在线视频图像信息采集设备
    case MONITOR = 133; // 显示器
    case ALARM_INPUT = 134; // 报警输入设备（红外、烟感、门禁等）
    case ALARM_OUTPUT = 135; // 报警输出设备（警灯、警铃等）
    case AUDIO_INPUT = 136; // 语音输入设备
    case AUDIO_OUTPUT = 137; // 语音输出设备
    case MOBILE_TRANSMITTER = 138; // 移动传输设备
    case OTHER_PERIPHERAL = 139; // 其他外围设备
    case RELAY_OUTPUT = 140; // 报警输出设备（继电器/触发器）
    case BARRIER_GATE = 141; // 道闸（车辆通行）
    case SMART_DOOR = 142; // 智能门（人员通行）
    case CREDENTIAL_READER = 143; // 凭证识别单元

    // =============== 平台设备 (200 ~ 299) ===============
    case SIGNAL_SERVER = 200; // 中心信令控制服务器
    case MEDIA_DISTRIBUTION = 201; // 媒体分发服务器（注：与201重复，标准如此）
    case PROXY_SERVER = 203; // 代理服务器
    case SECURITY_SERVER = 204; // 安全服务器
    case ALARM_SERVER = 205; // 报警服务器
    case DATABASE_SERVER = 206; // 数据库服务器
    case GIS_SERVER = 207; // GIS服务器
    case MANAGEMENT_SERVER = 208; // 管理服务器
    case GATEWAY = 209; // 接入网关
    case MEDIA_STORAGE = 210; // 媒体存储服务器
    case SIGNAL_SECURITY_GATEWAY = 211; // 信令安全路由网关
    case BUSINESS_GROUP = 215; // 业务分组
    case VIRTUAL_ORG = 216; // 虚拟组织

    // =============== 方法 ===============
    public function label(): string
    {
        return match ($this) {
            // 前端主设备
            self::DVR => 'DVR',
            self::VIDEO_SERVER => '视频服务器',
            self::ENCODER => '编码器',
            self::DECODER => '解码器',
            self::VIDEO_MATRIX => '视频切换矩阵',
            self::AUDIO_MATRIX => '音频切换矩阵',
            self::ALARM_CONTROLLER => '报警控制器',
            self::NVR => 'NVR',
            self::ONLINE_VIDEO_SYSTEM => '在线视频采集系统',
            self::VIDEO_TOLLGATE => '视频卡口',
            self::MULTI_CAMERA => '多目设备',
            self::PARKING_GATE => '停车场出入口',
            self::PERSONNEL_GATE => '人员出入口',
            self::SECURITY_CHECK => '安检设备',
            self::HVR => '混合硬盘录像机 (HVR)',

            // 前端外围设备
            self::CAMERA => '摄像机',
            self::IPC => '网络摄像机 (IPC)',
            self::MONITOR => '显示器',
            self::ALARM_INPUT => '报警输入设备',
            self::ALARM_OUTPUT => '报警输出设备',
            self::AUDIO_INPUT => '语音输入设备',
            self::AUDIO_OUTPUT => '语音输出设备',
            self::MOBILE_TRANSMITTER => '移动传输设备',
            self::OTHER_PERIPHERAL => '其他外围设备',
            self::RELAY_OUTPUT => '继电器输出设备',
            self::BARRIER_GATE => '道闸',
            self::SMART_DOOR => '智能门',
            self::CREDENTIAL_READER => '凭证识别单元',

            // 平台设备
            self::SIGNAL_SERVER => '信令控制服务器',
            self::MEDIA_DISTRIBUTION => '媒体分发服务器',
            self::PROXY_SERVER => '代理服务器',
            self::SECURITY_SERVER => '安全服务器',
            self::ALARM_SERVER => '报警服务器',
            self::DATABASE_SERVER => '数据库服务器',
            self::GIS_SERVER => 'GIS服务器',
            self::MANAGEMENT_SERVER => '管理服务器',
            self::GATEWAY => '接入网关',
            self::MEDIA_STORAGE => '媒体存储服务器',
            self::SIGNAL_SECURITY_GATEWAY => '信令安全路由网关',
            self::BUSINESS_GROUP => '业务分组',
            self::VIRTUAL_ORG => '虚拟组织',

            default => "未知类型 ({$this->value})",
        };
    }

    /**
     * 是否为前端主设备（可挂子设备）
     */
    public function isMainDevice(): bool
    {
        return $this->value >= 111 && $this->value <= 130;
    }

    /**
     * 是否为前端外围设备（叶子节点，如 IPC、报警）
     */
    public function isPeripheral(): bool
    {
        return $this->value >= 131 && $this->value <= 199;
    }

    /**
     * 是否为平台设备
     */
    public function isPlatform(): bool
    {
        return $this->value >= 200 && $this->value <= 299;
    }

    /**
     * 是否为报警类设备（输入/输出）
     */
    public function isAlarm(): bool
    {
        return in_array($this, [
            self::ALARM_INPUT,
            self::ALARM_OUTPUT,
            self::RELAY_OUTPUT,
            self::ALARM_CONTROLLER,
            self::ALARM_SERVER,
        ], true);
    }

    /**
     * 是否为视频源（可用于点播）
     */
    public function isVideoSource(): bool
    {
        return in_array($this, [
            self::CAMERA,
            self::IPC,
            self::DVR,
            self::NVR,
            self::HVR,
            self::VIDEO_TOLLGATE,
            self::MULTI_CAMERA,
        ], true);
    }

    /**
     * 安全转换
     */
    public static function tryFromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }
}