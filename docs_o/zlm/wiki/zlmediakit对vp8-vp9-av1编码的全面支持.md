## 一、摘要
在9月26号，zlmediakit的核心开发者之一 @Dw9 同学提交了一个av1编码支持的pr[1]，在该pr中实现了对av1编码的初步支持，新增了av1的rtpencoder个rtpdecoder类，支持rtsp/webrtc/mp4等协议对av1的支持，但是还未实现ertmp(增强型rtmp)对av1的支持；几周后，也就是前两天(10月15日)，我们另外一位核心开发者 @mtdxc 同学提交了一个新的pr[2]，对之前的av1编码相关功能进行了增强，完善了对ertmp的支持；与此同时，该pr还新增了rtsp/webrtc/mp4/rtmp等协议对vp8、vp9的全面支持，同时新增opus对ertmp的支持。目前上述pr都已经合并至master分支，至此，zlmediakit所有协议已经全面支持vp8、vp9、av1编码，加上之前已有的h264/h265/g711/aac/mp3编码，zlmediakit对各编码格式的支持在开源界可谓是一骑绝尘！


## 二、各编码格式使用初体验
### 2.1、各协议对vp8编码的支持
- webrtc推流：
![](https://upload-images.jianshu.io/upload_images/8409177-0561c6330c25cd74.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- rtsp播放：
![](https://upload-images.jianshu.io/upload_images/8409177-6b944d80df84ae80.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- rtmp(flv)播放：
目前ffmpeg8.0对vp8的ertmp格式支持还不完善，vp8编码格式无法识别；以下使用zlmediakit的test_player播放器测试，可正常出图:
![](https://upload-images.jianshu.io/upload_images/8409177-b3fdac0310d06a6d.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- http-fmp4播放:
![](https://upload-images.jianshu.io/upload_images/8409177-6c4bf9a28c36f7d1.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- mp4录制：
![](https://upload-images.jianshu.io/upload_images/8409177-373f775a860b1972.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- mp4点播：
![](https://upload-images.jianshu.io/upload_images/8409177-e31ed28d2f90b87d.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- ts(hls)播放：
目前ffmpeg8.0对vp8的ts格式支持还不完善，vp8编码格式无法识别；以下使用zlmediakit的test_player播放器测试，可正常出图:
![](https://upload-images.jianshu.io/upload_images/8409177-aec5bce103b3a5ff.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

### 2.2、各协议对vp9编码的支持
- webrtc推流：
![](https://upload-images.jianshu.io/upload_images/8409177-9b7d83a92fc5743e.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- rtsp播放：
![](https://upload-images.jianshu.io/upload_images/8409177-360e34641040c1bb.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- rtmp(flv)播放：
![](https://upload-images.jianshu.io/upload_images/8409177-27db6d52c1cb99df.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- http-fmp4播放：
ffmpeg8.0测试未通过，但是vlc测试通过：
![](https://upload-images.jianshu.io/upload_images/8409177-c0892de1cfdca59f.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- mp4录制：
ffmpeg8.0测试未通过，但是vlc测试通过：
![](https://upload-images.jianshu.io/upload_images/8409177-2234ff0b35b64444.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- mp4点播：
![](https://upload-images.jianshu.io/upload_images/8409177-703bc92eb9e89c52.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- ts/hls播放：
目前ffmpeg8.0对vp9的ts格式支持还不完善，vp9编码格式无法识别；以下使用zlmediakit的test_player播放器测试，可正常出图:
![](https://upload-images.jianshu.io/upload_images/8409177-e684c6c4aabd82bb.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

### 2.3、各协议对av1编码的支持
- webrtc推流：
![](https://upload-images.jianshu.io/upload_images/8409177-237b016e07e7d6aa.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- rtsp播放：
ffmpeg8.0能正常识别av1，但是mac下无法解码：
![](https://upload-images.jianshu.io/upload_images/8409177-4c79b945a686e517.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- rtmp(flv)播放：
ffmpeg8.0能正常识别av1，但是mac下无法解码：
![](https://upload-images.jianshu.io/upload_images/8409177-9f5f528dba896c89.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- http-fmp4播放：
ffmpeg8.0能正常识别av1，但是mac下无法解码，但是chrome可以播放成功：
![](https://upload-images.jianshu.io/upload_images/8409177-24886dc5cda59455.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- mp4录制：
ffmpeg8.0能正常识别av1，但是mac下无法解码，quicktime播放成功：
![](https://upload-images.jianshu.io/upload_images/8409177-f17841adf6ae27ca.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- mp4点播：
ffmpeg8.0能正常识别av1，但是mac下无法解码：
![](https://upload-images.jianshu.io/upload_images/8409177-3f94d06ac370b248.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)

- ts/hls播放：
目前ffmpeg8.0对av1的ts格式支持还不完善，av1编码格式无法识别；而且mac下ffmpeg也不支持av1解码，测试无法通过。


## 三、致谢
在此，对 @Dw9和@mtdxc同学的卓越贡献表示由衷的感谢，在他们的努力下，zlmediakit对各编码格式的支持日臻完善；同时，还非常感谢其他开发者对zlmediakit的厚爱和支持，以及广大用户对zlmediakit的信任和支持以及意见建议。

> [1]: https://github.com/ZLMediaKit/ZLMediaKit/pull/4389
> [2]: https://github.com/ZLMediaKit/ZLMediaKit/pull/4498

