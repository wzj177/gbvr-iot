<?php

namespace CoreW\Business\Devices\Enums;

/**
 * GB28181 设备分类枚举
 *
 * 根据 GB/T 28181-2016 标准，设备ID第10-13位为设备分类编码
 */
enum DeviceCategoryEnum: int
{
    // 前端设备 (100-199)
    case VIDEO_INPUT = 111;      // 视频输入设备（摄像机、IPC）
    case AUDIO_INPUT = 112;      // 音频输入设备
    case ALARM_INPUT = 113;      // 报警输入设备
    case DEVICE_CONTROL = 114;   // 设备控制（云台、镜头等）
    case MOBILE_TRANSPORT = 115; // 移动传输设备
    case VIDEO_OUTPUT = 116;     // 视频输出设备
    case AUDIO_OUTPUT = 117;     // 音频输出设备
    case ALARM_OUTPUT = 118;     // 报警输出设备

    // 平台设备 (130-139)
    case COMMAND_CENTER = 131;   // 指挥中心
    case PLATFORM = 132;         // 网络视频监控平台
    case SUB_CONTROL = 133;      // 分控中心
    case VIRTUAL_GROUP = 134;    // 业务分组/虚拟组织
    case MOBILE_TERMINAL = 135;  // 移动终端（单兵设备、执法记录仪）
    case PORTABLE_DEVICE = 136;  // 便携式设备

    // 存储/转发设备 (200-299)
    case DVR = 200;              // 数字硬盘录像机
    case NVR = 201;              // 网络硬盘录像机
    case ENCODER = 202;          // 视频编码器
    case DECODER = 203;          // 视频解码器
    case GATEWAY = 204;          // 网关
    case MATRIX = 205;           // 矩阵
    case VEHICLE_DEVICE = 215;   // 车载终端

    /**
     * 获取设备分类名称
     */
    public function label(): string
    {
        return match($this) {
            self::VIDEO_INPUT => '摄像机',
            self::AUDIO_INPUT => '音频输入设备',
            self::ALARM_INPUT => '报警输入设备',
            self::DEVICE_CONTROL => '设备控制',
            self::MOBILE_TRANSPORT => '移动传输设备',
            self::VIDEO_OUTPUT => '视频输出设备',
            self::AUDIO_OUTPUT => '音频输出设备',
            self::ALARM_OUTPUT => '报警输出设备',
            self::COMMAND_CENTER => '指挥中心',
            self::PLATFORM => '视频监控平台',
            self::SUB_CONTROL => '分控中心',
            self::VIRTUAL_GROUP => '业务分组',
            self::MOBILE_TERMINAL => '移动终端',
            self::PORTABLE_DEVICE => '便携式设备',
            self::DVR => 'DVR',
            self::NVR => 'NVR',
            self::ENCODER => '视频编码器',
            self::DECODER => '视频解码器',
            self::GATEWAY => '网关',
            self::MATRIX => '矩阵',
            self::VEHICLE_DEVICE => '车载终端',
        };
    }

    /**
     * 获取设备分类图标
     */
    public function icon(): string
    {
        return match($this) {
            self::VIDEO_INPUT => 'video-camera',
            self::AUDIO_INPUT => 'audio',
            self::ALARM_INPUT => 'bell',
            self::MOBILE_TERMINAL => 'mobile',
            self::PORTABLE_DEVICE => 'tablet',
            self::DVR, self::NVR => 'database',
            self::VEHICLE_DEVICE => 'car',
            default => 'device',
        };
    }

    /**
     * 从设备ID解析设备分类
     *
     * @param string $deviceId 20位国标设备ID
     * @return self|null
     */
    public static function parseFromDeviceId(string $deviceId): ?self
    {
        if (strlen($deviceId) < 13) {
            return null;
        }

        // 提取第10-13位（索引9-12）
        $categoryCode = (int) substr($deviceId, 9, 3);

        return self::tryFrom($categoryCode);
    }

    /**
     * 获取所有分类的键值对（用于下拉选择）
     *
     * @return array
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'label' => $case->label(),
                'icon' => $case->icon(),
            ];
        }
        return $options;
    }

    /**
     * 获取所有分类的映射表
     *
     * @return array<int, string>
     */
    public static function map(): array
    {
        $map = [];
        foreach (self::cases() as $case) {
            $map[$case->value] = $case->label();
        }
        return $map;
    }

    /**
     * 判断是否为移动设备（需要位置订阅）
     */
    public function isMobileDevice(): bool
    {
        return match($this) {
            self::MOBILE_TERMINAL,
            self::PORTABLE_DEVICE,
            self::VEHICLE_DEVICE,
            self::MOBILE_TRANSPORT => true,
            default => false,
        };
    }
}
