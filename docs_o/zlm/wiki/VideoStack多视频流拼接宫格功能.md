# VideoStack多视频流拼接宫格功能

> 如需使用该功能，需要编译时开启 ENABLE_FFMPEG 和 ENABLE_X264 

### 

### 添加多屏拼接 (/index/api/stack/start)

- **方法**: POST

- **参数**:
  
  | 参数名    | 描述        | 备注                    |
  | ------ | --------- | --------------------- |
  | gapv   | 垂直间隙比例    |                       |
  | gaph   | 水平间隙比例    |                       |
  | width  | 拼接后的视频宽度  |                       |
  | height | 拼接后的视频高度  |                       |
  | url    | 视频流URL列表  | 需要拼接的视频流(RTSP/RTMP)   |
  | id     | 拼接任务的唯一标识 |                       |
  | row    | 宫格的行数     |                       |
  | col    | 宫格的列数     |                       |
  | span   | 自定义宫格跨度配置 | 把指定的格子合并为一个大格子  (焦点屏) |

- **请求示例**:

```json

{
    "gapv": 0.002,
    "gaph": 0.001,
    "width": 1920,
    "height": 1080,
    "url": [
        [
            "rtsp://example.com/live/stream1",
            "rtsp://example.com/live/stream2",
            "rtsp://example.com/live/stream3",
            "rtsp://example.com/live/stream4"
        ],
        [
            "rtsp://example.com/live/stream5",
            "rtsp://example.com/live/stream6",
            "rtsp://example.com/live/stream7",
            "rtsp://example.com/live/stream8"
        ],
        [
            "rtsp://example.com/live/stream9",
            "rtsp://example.com/live/stream10",
            "rtsp://example.com/live/stream11",
            "rtsp://example.com/live/stream12"
        ],
        [
            "rtsp://example.com/live/stream13",
            "rtsp://example.com/live/stream14",
            "rtsp://example.com/live/stream15",
            "rtsp://example.com/live/stream16"
        ]
    ],
    "id": "stack_test",
    "row": 4,
    "col": 4,
    "span": [
                [
                    [0, 0], [1, 1]
                ], 
                [
                    [3, 2], [3, 3]
                ]
            ]
}
```



### 关闭多屏拼接 (/index/api/stack/stop)

> 已默认添加了当触发无人观看时，自动关闭（该接口用于主动关闭）

* **方法**: GET
* **参数说明**:

| 参数名 | 描述        | 备注  |
| --- | --------- | --- |
| id  | 拼接任务的唯一标识 |     |



- 拼接示意图(4行4列)，对应上面的示例json，如果span参数为空数组时，则为均分的宫格屏

![1c8547dc-0fcb-4f33-84b1-d180dfb977d7](file:///C:/Users/Administrator/Pictures/Typedown/1c8547dc-0fcb-4f33-84b1-d180dfb977d7.png)
