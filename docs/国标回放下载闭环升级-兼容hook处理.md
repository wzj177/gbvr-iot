#  一、数据库需要新增字段 -- 我已经增加了

只新增 **2 个字段**

```sql
ALTER TABLE gv_record_task
ADD COLUMN invite_ok_time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'INVITE成功时间',
ADD COLUMN last_rtp_time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后检测到RTP时间';
```

---

#  二、状态机不需要新增状态

你现在这套：

```
pending
inviting
wait_stream
recording
finalizing
done
failed
cancelled
```

**完全够用**

 不要新增：

* stream_online
* stream_offline
* published
* stopped

所有信号 → 转换为字段变化即可。

---

#  三、Hook 只做字段赋值（可选）

> hook 只是加速器
> 不参与业务判断

---

##  on_publish

```php
update gv_record_task
set record_start_time = IF(record_start_time=0, UNIX_TIMESTAMP(), record_start_time)
where stream_id = ?
```

---

##  on_stream_none_reader

```php
update gv_record_task
set record_end_time = IF(record_end_time=0, UNIX_TIMESTAMP(), record_end_time)
where stream_id = ?
```

---

- 不改 status
- 不调用 startRecord
- 不调用 stopRecord

---

#  四、Scheduler 每轮统一做 3 件事

---

## ① 查询 RTP 是否存在

```php
$rtp = $zlm->getRtpInfo($task['stream_id']);
```

---

## ② 如果 RTP 存在 → 更新 last_rtp_time

```php
if ($rtp && $rtp['exist']) {
    update last_rtp_time = time()
}
```

---

## ③ 统一使用 last_rtp_time 做状态判断

---

#  五、启动录像判断（WAIT_STREAM → RECORDING）

```php
if (
    $task['status'] == WAIT_STREAM &&
    $task['last_rtp_time'] > 0 &&
    time() - $task['last_rtp_time'] >= 3
) {
    startRecord();
    update status = RECORDING;
    update record_start_time = time();
}
```

---

#  六、结束录像判断（RECORDING → FINALIZING）

```php
if (
    $task['status'] == RECORDING &&
    time() - $task['last_rtp_time'] >= 10
) {
    stopRecord();
    update status = FINALIZING;
    update record_end_time = time();
}
```

---

#  七、完成任务（FINALIZING → DONE）

```php
if ($task['status'] == FINALIZING) {
    update record_duration =
        record_end_time - record_start_time;

    update status = DONE;
}
```

---

#  八、不开 hook 时是否可跑？

✔ 可以

因为：

```
RTP存在 → scheduler更新last_rtp_time
```

所有判断都基于：

```
last_rtp_time
```

hook 只是在 publish / none_reader 时**提前填充时间**

---

#  九、开启 hook 时会发生什么？

只是更快：

* record_start_time 更准
* record_end_time 更准

但：

```
业务逻辑仍然只看 last_rtp_time
```

---

#  十、完整数据流

```
创建任务
  ↓
INVITE OK → status=WAIT_STREAM
  ↓
scheduler发现RTP → last_rtp_time
  ↓
稳定3秒 → startRecord → RECORDING
  ↓
RTP消失10秒 → stopRecord → FINALIZING
  ↓
计算时长 → DONE
```

---

#  十一、这套方案的本质

✔ 不依赖 ZLM
✔ 不依赖 hook
✔ 不依赖回调顺序
✔ 兼容 SRS / ZLM / 未来任意流媒体
✔ 只依赖：**是否有 RTP**

---

#  你只需要做 4 件事

1、 执行 SQL 增加 2 字段
2、scheduler 更新 last_rtp_time
3、 改启动 / 停止录像判断
4、 hook 可选加字段赋值

---
