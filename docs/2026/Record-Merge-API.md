# GB28181 录像合并 API 文档

> Base URL: `/api/admin/gb28181`
> 认证：需要 Admin Token（`AuthIdentityMiddleware` + `PermissionCheckMiddleware`）

---

## 合并任务管理

### 1. 创建合并任务

```
POST /record-merge-tasks
```

将指定设备和通道在某个时间范围内的多个录像片段合并为一个 MP4 文件。

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| device_id | string | 是 | 设备国标 ID |
| channel_id | string | 是 | 通道国标 ID |
| start_time | int | 是 | 合并开始时间（时间戳） |
| end_time | int | 是 | 合并结束时间（时间戳） |

**请求示例：**

```json
{
  "device_id": "34020000001320000001",
  "channel_id": "34020000001310000001",
  "start_time": 1709000000,
  "end_time": 1709028000
}
```

**校验逻辑：**

1. `start_time` 必须小于 `end_time`
2. 查询 `gv_record_file` 表中该设备+通道在此时间范围内有交集的所有录像文件
3. 如果范围内没有录像文件，返回错误
4. 如果相同设备+通道+时间范围已存在 `pending`/`merging`/`done` 状态的任务，返回错误（避免重复合并）

**响应示例：**

```json
{
  "code": 0,
  "message": "合并任务已创建",
  "data": {
    "id": 1,
    "device_id": "34020000001320000001",
    "channel_id": "34020000001310000001",
    "start_time": 1709000000,
    "end_time": 1709028000,
    "start_time_formatted": "2026-02-27 10:00:00",
    "end_time_formatted": "2026-02-27 18:00:00",
    "source_file_ids": [101, 102, 103, 104],
    "source_file_count": 4,
    "status": "pending",
    "output_path": "",
    "output_file_size": 0,
    "output_file_size_mb": 0,
    "output_duration": 0,
    "output_duration_formatted": null,
    "error_message": "",
    "started_at": null,
    "finished_at": null,
    "created_at": "2026-05-19 16:00:00",
    "updated_at": "2026-05-19 16:00:00"
  }
}
```

**错误码：**

| 错误码 | 说明 |
|--------|------|
| 4003202 | 时间范围无效（start >= end） |
| 4003203 | 该时间范围内没有录像文件 |
| 4003204 | 该时间范围已存在合并任务 |

---

### 2. 获取合并任务列表

```
GET /record-merge-tasks
```

**Query 参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| device_id | string | 否 | 设备国标 ID |
| channel_id | string | 否 | 通道国标 ID |
| status | string | 否 | 状态筛选：pending / merging / done / failed |
| start | int | 否 | 分页偏移，默认 0 |
| limit | int | 否 | 每页条数，默认 20 |

**响应示例：**

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": 1,
        "device_id": "34020000001320000001",
        "channel_id": "34020000001310000001",
        "start_time": 1709000000,
        "end_time": 1709028000,
        "start_time_formatted": "2026-02-27 10:00:00",
        "end_time_formatted": "2026-02-27 18:00:00",
        "source_file_ids": [101, 102, 103, 104],
        "source_file_count": 4,
        "status": "done",
        "output_path": "/data/record/2026/02/27/merge/merge_1_20260519160500.mp4",
        "output_file_size": 2147483648,
        "output_file_size_mb": 2048.00,
        "output_duration": 28000,
        "output_duration_formatted": "07:46:40",
        "error_message": "",
        "started_at": "2026-05-19 16:00:10",
        "finished_at": "2026-05-19 16:02:30",
        "created_at": "2026-05-19 16:00:00",
        "updated_at": "2026-05-19 16:02:30"
      }
    ],
    "paginator": { "total": 1, "offset": 0, "limit": 20 }
  }
}
```

---

### 3. 获取合并任务详情

```
GET /record-merge-tasks/{id}
```

**响应：** 返回单条合并任务，字段同上。

---

### 4. 取消合并任务

```
POST /record-merge-tasks/{id}/cancel
```

> 仅 `pending` 状态可取消。`merging` 状态不可取消（FFmpeg 已在执行）。

**响应：**

```json
{ "code": 0, "message": "已取消", "data": null }
```

---

### 5. 删除合并任务

```
DELETE /record-merge-tasks/{id}
```

> 仅 `done` 或 `failed` 状态可删除。删除时会一并删除合并后的 MP4 物理文件。

**响应：**

```json
{ "code": 0, "message": "删除成功", "data": null }
```

**错误码：**

| 错误码 | 说明 |
|--------|------|
| 4043201 | 合并任务不存在 |
| 4003205 | 当前状态不允许此操作 |

---

## 任务状态流转

```
  创建 → pending（等待处理）
           ↓
     Crontab 抢占（CAS 原子更新）
           ↓
        merging（FFmpeg 执行中）
        ↓              ↓
     成功(done)     失败(failed)
                       ↑
               用户取消(cancel)

  崩溃恢复：merging 超过 30 分钟 → 自动重置为 pending 重试
```

| 状态 | 说明 |
|------|------|
| pending | 等待 Crontab 处理 |
| merging | FFmpeg 正在合并 |
| done | 合并完成，可下载 |
| failed | 合并失败（error_message 记录原因） |

---

## 异步处理机制

- **Crontab**：`RecordMergeTask` 每 10 秒轮询一次（注册在 `ScheduleTaskProcess`）
- **并发控制**：使用 DB CAS 原子抢占 `UPDATE ... WHERE status = 'pending'`，不依赖 Redis Lock
- **崩溃恢复**：`merging` 超过 30 分钟的任务自动重置为 `pending` 重试
- **每次最多处理** 5 个 pending 任务

---

## FFmpeg 合并方式

使用 `ffmpeg -f concat -safe 0 -c copy` 直接拼接，不重编码：

```
1. 查询 source_file_ids 对应的 gv_record_file 记录
2. 按开始时间排序
3. 生成临时 concat 文件列表（每行: file '/path/to/segment.mp4'）
4. ffmpeg -y -f concat -safe 0 -i list.txt -c copy output.mp4
5. 合并完成 → 更新 output_path / output_file_size / output_duration
6. 清理临时文件
```

**注意**：要求源录像文件编码格式一致（均为 ZLM 同一配置产出，天然一致）。合并速度取决于磁盘 IO，不消耗 CPU。

---

## 数据库表

### gv_record_merge_tasks

| 列名 | 类型 | 说明 |
|------|------|------|
| id | int unsigned | 自增主键 |
| device_id | varchar(64) | 设备国标 ID |
| channel_id | varchar(64) | 通道国标 ID |
| start_time | int unsigned | 合并开始时间戳 |
| end_time | int | 合并结束时间戳 |
| source_file_ids | text | 源录像文件 ID 列表（JSON 数组） |
| source_file_count | int | 源文件数量 |
| status | varchar(20) | 状态: pending/merging/done/failed |
| output_path | varchar(500) | 合并后文件路径 |
| output_file_size | bigint | 合并后文件大小（字节） |
| output_duration | int | 合并后时长（秒） |
| error_message | varchar(500) | 失败原因 |
| started_at | datetime | 开始合并时间 |
| finished_at | datetime | 合并完成时间 |
| created_at | datetime | 创建时间 |
| updated_at | datetime | 更新时间 |

**索引：**
- `idx_device_channel` (device_id, channel_id)
- `idx_status` (status)
- `idx_time_range` (device_id, channel_id, start_time, end_time, status) — 用于去重检查

---

## 前端集成建议

1. 在录像管理页面增加「合并录像」Tab
2. 选择设备+通道 → 选择时间范围 → 提交合并
3. 列表展示合并任务，`status` 列用不同颜色标签（pending 黄、merging 蓝、done 绿、failed 红）
4. `done` 状态的任务提供下载按钮（直接下载 `output_path` 对应的文件）
5. 列表支持自动刷新（每 5-10 秒轮询），方便观察 merging → done 的变化

---

**版本**: v1.0.0
**更新日期**: 2026-05-19
