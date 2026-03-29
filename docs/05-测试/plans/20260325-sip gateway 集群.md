# 集群


实现一个api支持多个sip 网关，允许管理后台实现sip gateway 管理，同时设备上线根据ip+port 自动绑定gateway id。

## 技术说明

### 核心配置
目前我们单网关配置在在`config/gb28181.php`，现在升级集群，我们要存db，同时增加多种通信方式，且通信方式存db且后台可以添加时配置
### 网关通信

目前我们的网关和api的对接是通过redis 队列实现，在`config/gb28181.php`配置。我们现在要支持redis、RocketMQ、Kafka mq 三种方式，然后还要对redis进行优化。
这个改造涉及 api 端的生产者sdk和网关端的消费者

#### redis 方案

```
行，给你压缩成**能直接照着实现的最小模型**：

---

# ✅ Laravel Redis Queue 核心总结（极简版）

### **1. 四个 key**

```
queue:list        // 主队列（list）
queue:delayed     // 延迟队列（zset）
queue:reserved    // 处理中（zset）
queue:notify      // 阻塞通知（list）
```

---

### **2. 入队**

```
LPUSH queue:list job
LPUSH queue:notify 1
```

---

### **3. 延迟任务**

```
ZADD queue:delayed timestamp job
```

---

### **4. 消费前（关键）**

```

#### RocketMQ  方案
#### Kafka 方案


#### 生产端代码架构

- driver: redisSdk、rocketmqSdk、kafkaSdk，abstractSdk，SdkInterface（checkConfig、connect 、send...)
- manager：SdkManager（instance）
- service：SdkService（send）

#### 消费端代码架构
同生产端类似


## api设计
- check config
- add gateway
- delete gateway
- update gateway
- list gateway
- status  gateway
# 到期任务
delayed → list

# 超时任务（retry）
reserved → list
```

---

### **5. 消费（必须 Lua 原子）**

```
job = RPOP list
ZADD reserved (now + retry_after) job
```

---

### **6. 空队列阻塞**

```
BLPOP queue:notify
```

---

### **7. 成功确认**

```
ZREM reserved job
```

---

### **8. 失败重试**

```
ZREM reserved job
ZADD delayed (now + delay) job
```

---

👉 **list 做队列，zset 做时间控制，reserved 做可靠性，lua 保证原子性**

```

