## 一、摘要
在8月10号，我们在github收到了一个来自 @baigao-X 同学的[重磅pr](https://github.com/ZLMediaKit/ZLMediaKit/pull/4389)(pull request, 拉取合并请求)。简而言之，该pr实现了完整的webrtc ice-full功能，也就是说，zlmediakit在该分支可以实现完整的webrtc功能，不仅仅包括之前基于ice-lite实现的webrtc服务端，还能作为webrtc客户端在NAT内主动发起webrtc请求。基于该特性，zlmediakit可以作为webrtc播放器主动拉流，也可以作为webrtc推流器主动推流，还可以作为P2P客户端双向视频会话。

## 二、功能介绍

### 2.1、作为webrtc播放器
- 使用addStreamProxy http api主动拉取webrtc流并代理：
![](https://github.com/user-attachments/assets/fe9b7be5-ab99-4873-a2a3-ad436bc525ce)
![](https://github.com/user-attachments/assets/fd666589-b7da-4a0e-9759-247e308cc898)

- 使用MediaPlayer(c++)接口播放webrtc流:
![](https://github.com/user-attachments/assets/f619438f-ba95-4120-ba92-5991745d476d)
![](https://github.com/user-attachments/assets/33de1598-709b-47ba-b5b9-a1059abb3f0f)
> 用法请参考 https://github.com/ZLMediaKit/ZLMediaKit/blob/master/player/test_player.cpp
        
- 使用mk_player(c sdk)接口播放webrtc流:
![](https://github.com/user-attachments/assets/2817e7c9-215c-4c1f-b406-baaa7078110c)
> 用法请参考 https://github.com/ZLMediaKit/ZLMediaKit/blob/master/api/tests/player_opencv.c
  
    
### 2.2、作为webrtc推流器
- 使用addStreamPusherProxy主动推送webrtc流：
![](https://github.com/user-attachments/assets/58d36e90-ff78-42a1-89b3-9f1f789e9b2e)
![](https://github.com/user-attachments/assets/a04165c7-14d5-4068-8f4a-1767efe99b39)

 - 使用MediaPusher(c++)接口主动推送webrtc流：
![](https://github.com/user-attachments/assets/c633f10b-98c3-4d2e-8259-1eb88098a3b8)
![](https://github.com/user-attachments/assets/556c0b58-799b-4693-b417-847b2ab39d44)
> 用法请参考：https://github.com/ZLMediaKit/ZLMediaKit/blob/master/tests/test_pusherMp4.cpp
    
 - 使用mk_pusher(c sdk)接口主动推送webrtc流：
![](https://github.com/user-attachments/assets/c7efc5f7-49ee-4711-b6c1-a95e9498fdae)
![](https://github.com/user-attachments/assets/b16c3068-f603-4d5a-b41d-ffd0920df5c9)    
> 用法请参考: https://github.com/ZLMediaKit/ZLMediaKit/blob/master/api/tests/h264_pusher.c

### 2.3、更多用法介绍
另外还支持p2p相关功能，详情请参考：https://github.com/ZLMediaKit/ZLMediaKit/blob/master/webrtc/USAGE.md   
    
## 三、快速尝鲜
- 通过docker方式：
```bash
#此镜像为github action 持续集成自动编译推送，跟代码(master分支)保持最新状态
docker run -id -p 1935:1935 -p 8080:80 -p 8443:443 -p 8554:554 -p 10000:10000 -p 10000:10000/udp -p 8000:8000/udp -p 9000:9000/udp zlmediakit/zlmediakit:master
```

- 通过二进制包：
用户可以在该issue下载zlmediakit最新代码自动发布的二进制包：
https://github.com/ZLMediaKit/ZLMediaKit/issues/483
    
## 四、友情提示
- 关于稳定性：
在经过一个多月的审核、完善(共计70多个提交)、测试工作后，该pr目前已经合并进master主分支并升级为9.0版本，原稳定主分支存档为8.0分支。目前经过我们的测试，原有功能(作为webrtc服务端)基本可以保障稳定运行，其他非webrtc相关功能改动很小，大家可以放心使用。但是由于webrtc ice相关代码经过重构，变动极大，测试和审核并不能面面俱到，新增的webrtc客户端相关功能，可能还尚待稳定。

- 关于url格式：
目前zlmediakit的webrtc客户端url采用webrtc[s]://前缀私有格式，代码逻辑在发现为该前缀后会根据一定的规则解析并且生成zlmediakit特有的whip、whep url(http[s])。所以目前zlmediakit还不能支持其他服务器的whip、whep url，用户可以通过[修改代码](https://github.com/ZLMediaKit/ZLMediaKit/blob/c82dd750548e136d80b08e57febea15ed62bc130/webrtc/WebRtcClient.cpp#L93) 兼容其他第三方webrtc服务器。

- 缺陷
目前webrtc客户端相关功能还不支持webrtc over tcp模式，只支持over udp模式。


## 五、致谢
在此，对 @baigao-X 同学的卓越贡献表示由衷的感谢！他对zlmediakit以及zltoolkit做出许多重要的贡献，包括zlmediakit中srt模块对客户端的完整支持，以及zltoolkit中kcp协议的支持。同时也非常感谢 @mtdxc 同学大量参与本次代码审核工作以及长期以来对zlmediakit webrtc相关功能以及转码分支的贡献。
另外，还非常感谢其他开发者对zlmediakit的厚爱和支持，以及广大开发者对zlmediakit的信任和意见建议。