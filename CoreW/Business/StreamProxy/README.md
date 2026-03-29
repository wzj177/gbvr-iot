# StreamProxy 模块

支持海康/大华等非国标摄像头RTSP流接入、OBS推流管理

## 快速链接

- 📖 [模块说明](./MODULE.md) - 完整的模块结构和技术说明
- 🚀 [API文档](../../../docs/StreamProxy-API.md) - 前端对接API接口文档

## 核心功能

- ✅ 拉流：海康/大华/宇视等RTSP摄像头
- ✅ 推流：OBS/FFmpeg等RTMP推流
- ✅ 多协议输出：RTSP/RTMP/HLS/FLV/WebSocket-FLV
- ✅ 健康检查：30秒自动心跳
- ✅ 自动重连：60秒智能重试
- ✅ 录像联动：绑定录像计划自动录像

## 技术栈

- 流媒体：ZLMediaKit (HTTP API)
- 数据库：MySQL
- 缓存：Redis
- 后台任务：Workerman Timer

## 快速开始

### 1. 部署

```bash
# 运行迁移
bin/phpmig migrate

# 重启服务
php start.php restart
```

### 2. 测试

```bash
# 创建流代理
curl -X POST "http://127.0.0.1:8886/api/admin/stream-proxies" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "测试摄像头",
    "type": "pull",
    "protocol": "rtsp",
    "source_url": "rtsp://admin:12345@192.168.1.100:554/Streaming/Channels/101",
    "media_server_id": "1"
  }'

# 启动
curl -X POST "http://127.0.0.1:8886/api/admin/stream-proxies/1/start"

# 获取播放地址
curl "http://127.0.0.1:8886/api/admin/stream-proxies/1/play-urls"
```

## 目录结构

```
StreamProxy/
├── README.md                         # 本文件
├── MODULE.md                         # 模块详细说明
├── Dao/
│   ├── StreamProxyDao.php
│   └── Impl/StreamProxyDaoImpl.php
├── Service/
│   ├── StreamProxyService.php
│   └── Impl/StreamProxyServiceImpl.php
└── Exception/
    └── StreamProxyException.php
```

## API接口

**Base URL**: `/api/admin/stream-proxies`

- `GET /` - 列表
- `POST /` - 创建
- `GET /{id}` - 详情
- `PUT /{id}` - 更新
- `DELETE /{id}` - 删除
- `POST /{id}/start` - 启动
- `POST /{id}/stop` - 停止
- `POST /{id}/restart` - 重启
- `GET /{id}/play-urls` - 播放地址
- `POST /{id}/bind-plan` - 绑定录像计划
- `POST /{id}/unbind-plan` - 解绑录像计划
- `GET /summary` - 统计摘要
- `POST /health-check` - 健康检查

详见：[API文档](../../../docs/StreamProxy-API.md)

---

**版本**: v1.0.0
