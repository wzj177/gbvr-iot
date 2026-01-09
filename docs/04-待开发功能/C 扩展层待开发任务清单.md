# C 扩展层待开发任务清单

> 本文档列出 php-exosip C 扩展层需要开发的功能

---

## 🔴 高优先级

### 1. SUBSCRIBE/NOTIFY 完整支持

**状态**: 🟡 部分实现

**需求**:
- ✅ 基础 SUBSCRIBE 请求发送
- ⚠️ NOTIFY 请求的接收和解析
- ⚠️ 订阅刷新机制（自动续订）
- ⚠️ 订阅状态管理（记录 Call-ID、expires）

**涉及函数**:
```c
// 需要实现或完善
int exosip_subscribe(const char *to, const char *event, int expires);
int exosip_notify_handle(eXosip_event_t *evt);  // 处理收到的 NOTIFY
int exosip_subscription_refresh(int dialog_id);  // 自动刷新订阅
```

**参考文档**:
- [国标设备扩展功能-订阅.md](国标设备扩展功能-订阅.md)
- [SUBSCRIBE_FEATURE_STATUS.md](../03-功能实现/SUBSCRIBE_FEATURE_STATUS.md)

---

### 2. TCP 主动模式完善

**状态**: 🟡 基本实现，需测试

**需求**:
- ✅ TCP 被动模式（设备连平台）
- ⚠️ TCP 主动模式（平台连设备）- SDP 中 a=setup:active
- ⚠️ 连接管理和超时重连
- ⚠️ 多设备并发 TCP 连接管理

**涉及配置**:
```c
// config.h 中需要支持
typedef struct {
    int tcp_mode;  // 0=UDP, 1=TCP被动, 2=TCP主动
    char device_ip[64];
    int device_port;
} tcp_config_t;
```

**测试文件**: `tests/test_tcp_mode.php`

**参考文档**: [TCP_MODE_SUPPORT.md](../03-功能实现/TCP_MODE_SUPPORT.md)

---

### 3. 事件类型扩展

**状态**: ⚠️ 需新增

**需求**: 支持更多 GB28181 事件类型

当前支持的事件:
- ✅ REGISTER
- ✅ MESSAGE
- ✅ INVITE / BYE
- ✅ INFO (keepalive)

需要新增:
- ⚠️ SUBSCRIBE
- ⚠️ NOTIFY
- ⚠️ UPDATE (会话更新)

**修改位置**: `exosip_wrapper.c` 中 `exosip_event_wait()` 函数

---

## 🟡 中优先级

### 4. 2022 版本信令支持

**状态**: ⚠️ 未开始

**需求**: 支持 GB/T 28181-2022 新增的控制命令

新增控制类型:
- DeviceControl: Telezoom (远程变倍)
- HomePosition (回到原点)
- ConfigDownload (配置下载)
- AudioBroadcast (语音广播)

**实现方式**: 
- C 扩展层只需确保 MESSAGE 请求能正确发送 XML body
- 不需要特殊的 C 代码，主要是 PHP 层构建 XML

**参考文档**:
- [扩展2022版本国标协议方案.md](扩展2022版本国标协议方案.md)
- [GB28181_PRESET_AND_2022_GUIDE.md](GB28181_PRESET_AND_2022_GUIDE.md)

---

### 5. 语音对讲底层支持

**状态**: ⚠️ 需调研

**需求**:
- Audio INVITE 会话建立（与 video 类似但媒体类型不同）
- 双向音频流管理
- 音频编码协商（G.711a/u、AAC 等）

**SDP 示例**:
```
m=audio 8000 RTP/AVP 8 0
a=rtpmap:8 PCMA/8000
a=rtpmap:0 PCMU/8000
a=sendrecv
```

**涉及函数**:
```c
// 类似 exosip_invite，但 SDP 为 audio
int exosip_audio_invite(const char *to, const char *sdp);
```

**参考文档**: [语音对讲.md](../03-功能实现/语音对讲.md)

---

### 6. SDP 解析增强

**状态**: ✅ 已实现基础，需扩展

**当前功能**:
- ✅ 解析 c= 连接地址
- ✅ 解析 m= 媒体端口
- ✅ 解析 a= 属性（setup、ssrc 等）
- ✅ 解析 y= SSRC

**需要增强**:
- ⚠️ 支持多个 m= 行（音视频混合）
- ⚠️ 解析完整的 rtpmap
- ⚠️ fmtp 参数解析（如 H.265 的 profile）

**参考文档**: [SDP_PARSER_NATIVE.md](../03-功能实现/SDP_PARSER_NATIVE.md)

---

## 🟢 低优先级

### 7. 性能优化

**需求**:
- 减少 PHP 与 C 之间的数据拷贝
- 使用共享内存存储设备列表
- 优化事件通知机制（避免轮询）

---

### 8. 错误处理增强

**需求**:
- 更详细的错误码定义
- 错误堆栈信息
- 连接断开自动恢复

**参考文档**: [CALLBACK_ERROR_HANDLING.md](../03-功能实现/CALLBACK_ERROR_HANDLING.md)

---

### 9. 日志系统改进

**需求**:
- 支持日志级别配置（DEBUG/INFO/WARN/ERROR）
- 日志轮转
- 性能数据统计

---

## 📋 开发优先级建议

| 优先级 | 任务 | 预计工作量 | 依赖 |
|--------|------|------------|------|
| P0 | SUBSCRIBE/NOTIFY 支持 | 3-5天 | 无 |
| P0 | TCP 主动模式完善 | 2-3天 | 无 |
| P1 | 事件类型扩展 | 1-2天 | 无 |
| P1 | 语音对讲底层支持 | 3-4天 | SDP 解析增强 |
| P2 | 2022 版本信令支持 | 1天 | 无（主要 PHP 层） |
| P2 | SDP 解析增强 | 2-3天 | 无 |
| P3 | 性能优化 | 5-7天 | 功能稳定后 |
| P3 | 错误处理增强 | 2-3天 | 无 |
| P3 | 日志系统改进 | 2-3天 | 无 |

**总计**: 约 20-30 个工作日

---

## 🔧 开发环境

**编译命令**:
```bash
cd /Users/jiechengyang/src/c-app/php-exosip
phpize
./configure
make
make install
```

**测试**:
```bash
php -m | grep exosip
php tests/test_client.php
```

**参考文档**:
- [BUILD_CENTOS.md](../05-部署运维/BUILD_CENTOS.md)
- [CENTOS_COMPILE.md](../05-部署运维/CENTOS_COMPILE.md)

---

## 📚 相关资源

- **eXosip2 官方文档**: http://savannah.nongnu.org/projects/exosip
- **GB28181 标准**: GB/T 28181-2016 和 GB/T 28181-2022
- **RFC 3265**: SIP-Specific Event Notification (SUBSCRIBE/NOTIFY)
- **RFC 4566**: SDP: Session Description Protocol
