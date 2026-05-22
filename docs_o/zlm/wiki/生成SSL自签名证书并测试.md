# 1、创建私钥
```bash
 openssl genrsa  -out server.key 2048
```

# 2、 创建签名请求文件
```bash
 openssl req -new -key server.key -out server.csr
```
注意，需要输入域名(Common Name (e.g. server FQDN or YOUR name))：
```bash
Country Name (2 letter code) [AU]:cn
State or Province Name (full name) [Some-State]:gd
Locality Name (eg, city) []:sz
Organization Name (eg, company) [Internet Widgits Pty Ltd]:company
Organizational Unit Name (eg, section) []:section
Common Name (e.g. server FQDN or YOUR name) []:zlm.com
Email Address []:xiachu@qq.com

Please enter the following 'extra' attributes
to be sent with your certificate request
A challenge password []:
An optional company name []:zlm
```

# 3、自签名，生成公钥（10年有效期）
```bash
openssl x509 -req -days 3650 -in server.csr -signkey server.key -out server.crt
```
执行该命令会打印以下信息：
```bash
Signature ok
subject=/C=cn/ST=gd/L=sz/O=company/OU=section/CN=zlm.com/emailAddress=xiachu@qq.com
Getting Private key
```

# 4、合并公钥私钥(需要私钥在前)
```bash
cat server.key server.crt > ./ssl.pem
```

# 5、加载证书
```bash
./MediaServer -s ./ssl.pem
```
![图片.png](https://upload-images.jianshu.io/upload_images/8409177-a3e64c0c8b642521.png?imageMogr2/auto-orient/strip%7CimageView2/2/w/1240)


# 6、如果第5步报错，检查格式是否正确
```bash
cat ./ssl.pem
-----BEGIN RSA PRIVATE KEY-----
base64内容
-----END RSA PRIVATE KEY-----
-----BEGIN CERTIFICATE-----
base64内容
-----END CERTIFICATE-----
```
如果不是-----BEGIN RSA PRIVATE KEY----- 可以重新开始第4部，将文件调换下顺序，重新合并

# 7、证书包含多ip或域名情况
## 创建服务器证书配置文件 server.conf
```
[ req ]
distinguished_name = req_distinguished_name
req_extensions = req_ext

[ req_distinguished_name ]
countryName = Country Name (2 letter code)
stateOrProvinceName = State or Province Name (full name)
localityName = Locality Name (eg, city)
organizationName = Organization Name (eg, company)
organizationalUnitName = Organizational Unit Name (eg, section)
commonName = Common Name (eg, fully qualified host name)
emailAddress = Email Address

[ req_ext ]
subjectAltName = @alt_names

[ alt_names ]
DNS.1 = localhost
DNS.2 = *.local
DNS.3 = wss.local
IP.1 = 192.168.1.10
IP.2 = 192.168.1.11
```
域名修改对应是DNS.1，ip修改对应IP.1，根据实际情况进行修改
## 命令顺序
```bash
openssl genpkey -algorithm RSA -out server.key
openssl req -new -key server.key -out server.csr -config server.cnf
openssl x509 -req -in server.csr -signkey server.key -out server.crt -extensions req_ext -extfile openssl.cnf -days 36500
cat server.key server.crt > ./ssl.pem
```