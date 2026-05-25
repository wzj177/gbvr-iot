# GBVR-IOT
基于php-exosip2 + webman 实现的国标x协议视频管理平台

## 核心功能
- 完整的gb信令服务
- 流媒体服务：对接zlm/srs，支持国标摄像头rtp推流，集成非GB28181设备的拉流功能，实现除GB28181 Rtp流以外的其他流类型的拉取与转换
- 云端录像
- 国标级联
- VR：支持倒入全景并提供摄像头点位标记等，实现3D VR + 实时预览
- 一张图：以高德/lefleat等地图sdk，实现设备一张图（设备点位、区域、预警）
- AI：视频文搜、视频行为算法
## 快速开始
### 安装
#### 环境要求
- PHP >= 7.2
- Composer >= 2.0
- 内存至少 4GB （系统会使用ImageMagick处理全景图片，生成对应全景图片的低分辨率图：如果全景图片在20mb以上就会有很大的内存开销，如果内存不足会导致生成低分辨率图失败的可能）
#### composer 安装
```shell script
composer config -g --unset repos.packagist
```
#### 包安装
```shell script
composer install -vvv
```
#### 修改环境配置文件`.env`，将自己的环境参数配置（一定阅读`.env.example`的注释)
```shell script
cp .env.example .env
```
#### 系统初始化

- 数据迁移

```shell
bin/phpmig migrate 
```
   
- 系统初始化
```shell
php webman system:init 
```
#### 运行系统
```shell script
php start.php start 
php start.php stop
php start.php status
php start.php restart
# 守护进程启动
php start.php restart -d
php start.php reload
```
### 基础配置

#### 数据库

- 多数据库
默认是单数据库；首先配置文件是和webman保持一致的，在`config/database.php`下，配置方式和webman一样就行

- 使用默认db
```php
$biz['db']->exec()
```
- 使用其它db
```php
$biz['dbs']['mysql2']->exec()
```

#### redis
- 开启dao层缓存

`config/redis.php`配置如下：增加dao-cache
```php
return [
    'default' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 0,
    ],
    'dao-cache' => [
        'host'     => '127.0.0.1',
        'password' => null,
        'port'     => 6379,
        'database' => 9,
    ],
];
```

#### 认证
- 开启jwt认证（开启jwt认证后，将按照jwt标准实行，因此header头的token信息将换成 Authorization: Bearer Token;如有需要更完善的jwt，可以对接下工具和资料下的第一个认证插件链接)

  修改配置文件`.env`
   ```php
  AUTH_TOKEN_HANDLER='jwt'
  JWT_SECRET=''
  JWT_PUBLIC_KEY=''
  JWT_PRIVATE_KEY=''
  JWT_TTL=60
  JWT_REFRESH_TTL=null
  JWT_ALGO=RS256
  JWT_LEEWAY=0
  JWT_BLACKLIST_ENABLED=true
  ```
- 如何开启多端认证
  修改`config/app.php`文件,将jwt_的主要参数配置到对应端:
```php
    'api' => [
        'auth_ttl' => '{默认token登录过期时间：7天}', 
        'jwt_ttl' => '{jwt access token 过期时间，如果设置则会覆盖默认的jwt配置}'
        'jwt_refresh_ttl' => '{jwt refresh token 过期时间，如果设置则会覆盖默认的jwt配置}',
        'jwt_secret' => '',
        'jwt_private_key' => '',
        'jwt_public_key' => '',
    ],
    '其它端' => [
        //
    ],
```
- 切换认证存储方式为redis（目前支持db和redis，默认为db）
  修改配置文件`.env`:
```
AUTH_TOKEN_STORAGE='redis'
```
### nginx
```shell
upstream webman-vr {
    server 127.0.0.1:8886;
    keepalive 10240;
}
server {
    server_name vr.com.cn;
    listen 80;
    access_log /var/logs/nginx/vr.com.cn.access.log;
    error_log  /var/logs/nginx/vr.com.cn.error.log;
    # 装修前端静态资源目录
    root /www/wwwroot/vr.com.cn/public/front;
    client_max_body_size 50m;
    client_body_buffer_size 2m;
    # ssl 配置
    # 处理 /api-static/ 的请求
    location ^~ /api-static {
        alias /www/wwwroot/vr.com.cn/public/api-static/;  # 指向实际的静态资源目录
        # 其他配置，例如缓存或 CORS 设置
        expires 30d;  # 缓存设置，30天过期
        add_header Cache-Control "public";
        try_files $uri $uri/ =404;
    }
    # 处理 /uploads/ 的请求
    location ^~ /uploads {
        alias /www/wwwroot/vr.com.cn/public/uploads/;  # 指向实际的静态资源目录
        # 其他配置，例如缓存或 CORS 设置
        expires 30d;  # 缓存设置，30天过期
        add_header Cache-Control "public";
        try_files $uri $uri/ =404;
    }
    # 运营管理后台静态资源目录
    location ^~ /admin-ui {
        alias /www/wwwroot/vr.com.cn/public/admin-ui/;
        try_files $uri $uri/ /admin-ui/index.html;
    }
    # 处理静态文件的请求
    location / {
        try_files $uri $uri/ /index.html;
    }
    # 开放api、运营管理后台的请求
    location ~ ^/(api|admin)/ {
          proxy_set_header X-Real-IP $remote_addr;
          proxy_set_header Host $host;
          proxy_set_header X-Forwarded-Proto $scheme;
          proxy_http_version 1.1;
          proxy_set_header Connection "";
          proxy_buffering  on;
          proxy_buffer_size 500M;
          proxy_buffers 4 500M;
          proxy_busy_buffers_size 500M;
          proxy_temp_file_write_size 500M;
          if (!-f $request_filename){
              proxy_pass http://webman-vr;
          }
    }
    # 大文件下载借用 fpm, 需要修改.env 配置内的安全参数:BIG_FILE_DOWNLOAD_REFERER_WHITE_LIST='文件分段下载，download.php 允许来源访问白名单(多个 url 以英文｜分割)' 
    location ^~ admin/download.php {
            fastcgi_pass   unix:/var/run/php/php7.4-fpm.sock;
            fastcgi_index  index.php;
            fastcgi_split_path_info  ^((?U).+\.php)(/?.+)$;
            fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
            fastcgi_param  PATH_INFO  $fastcgi_path_info;
            fastcgi_param  PATH_TRANSLATED  $document_root$fastcgi_path_info;
            include        fastcgi_params;
            try_files $uri =404;
#            try_files $uri =404;
#            fastcgi_pass  unix:/tmp/php-cgi-74.sock;
#            fastcgi_index index.php;
#            include fastcgi.conf;
#            include pathinfo.conf;
    }
       
  }
    }
```

### 常用业务指令

#### 生成内部会员命令
```shell
php webman make:vip
```
#### 审核公司命令
```shell
php webman company:check
```
#### 生成业务层指令
```shell
# 例如:生成商品业务层
php webman make:biz -i Goods 
# 例如:在某个插件目录下生成业务层
php webman make:biz -i Coupon --namespace {plugin}\Business\Coupon
# 例如: 生成学生业务层并指定数据表名称
php webman make:biz -i Student -s students
```

#### 生成业务dao层指令
`一般用于已经生成的service层追加关联的业务dao`
```shell
# 例如:生成product 下 catalog dao
 php webman make:biz-dao -i Product -d ProductCatalog 
```

## 系统消息队列任务表
- 删除全景场景任务：
- 生成大文件分片任务：
- 清理大文件分片上传的临时片段文件：
- 文件上传后异步任务：对视频文件进行取帧、计算时长等
- 全景图片矢量图分片任务：
- 删除文件任务：
- 发送邮件任务：
- 会员邮箱验证任务：


## gbs启动
- 如果是UDP监听：`cp config/gb28181.php.example config/gb28181.php`
- 如果是TCP监听：`cp config/gb28181_tcp.php.example config/gb28181_tcp.php`
- UDP启动：`php webman gb28181:server start`
- TCP启动：`php webman gb28181:server start --tcp`
## 注意
### daoProxy
当开启了dao层缓存后，在dao里面以以下单词开头命名或直接命名的方法会被代理从而缓存dao层拿到的db数据，因为会走daoProxy
`'get', 'find', 'search', 'count', 'create', 'batchCreate', 'batchUpdate', 'batchDelete', 'update', 'wave', 'delete'`

## 工具和资料

- [Lcobucci/jwt Integration For webman](https://www.workerman.net/plugin/45)
- [接口设计](https://www.easemob.com/news/6806)
- [在线生成公钥私钥对，RSA公私钥生成-ME2在线工具](http://www.metools.info/code/c80.html)