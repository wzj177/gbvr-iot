<?php


namespace CoreW\Sdk\Ys7Sdk;

use CoreW\Bfw;
use CoreW\JsonLogger;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 *
 * 萤石云接口对接v1.0
 * Created by PhpStorm.
 * User: Yangjiecheng
 * Date: 2020/5/21 0021
 * Time: 下午 15:51
 */
class OpenYs7
{
    /**
     *
     */
    const ACCESS_TOKEN_KEY = 'open:ys7:access_token';

    const MAX_LOG_SIZE = 1024 * 1024 * 10;

    /**
     * @var mixed
     */
    private $appKey;
    /**
     * @var mixed
     */
    private $appSecret;
    /**
     * token过期时间，单位：s
     * @var int|mixed
     */
    private $tokenTtl = 604200;// 7天-10分钟
    /**
     * @var mixed|string
     */
    private $module = 'lapp';
    /**
     * @var string
     */
    private $apiUri = 'https://open.ys7.com/api';
    /**
     * @var bool
     */
    private $hashKey = false;
    private $debug = true;
    /**
     *
     * 开发平台api错误
     * @var array
     */
    public $errorMap
        = [
            10001 => '参数为空或格式不正确',//appKey不能为空
            10002 => '重新获取accessToken',
            10005 => 'appKey被冻结',
            10030 => 'appKey和appSecret不匹配',
            20002 => '设备不存在',
            20006 => '检查设备网络状况，稍后再试',
            20007 => '检查设备是否在线',
            20008 => '操作过于频繁，稍后再试',
            20014 => 'deviceSerial不合法',
            20018 => '该用户不拥有该设备, 检查设备是否属于当前账户',
            20032 => '该用户下通道不存在',
            49999 => '数据异常',
            60000 => '设备不支持云台控制',
            60001 => '用户无云台控制权限',
            60002 => '设备云台旋转达到上限位',
            60003 => '设备云台旋转达到下限位',
            60004 => '设备云台旋转达到左限位',
            60005 => '设备云台旋转达到右限位',
            60006 => '云台当前操作失败，请稍候再试',
            60009 => '正在调用预置点',
            60020 => '不支持该命令,确认设备是否支持预览操作',
            60061 => '账户流量已超出或未购买，限制开通',
            60062 => '该通道直播已开通',
            60068 => '设备响应超时',
        ];
    /**
     *
     * 云台操作指令
     * @var array
     */
    public $directionMap
        = [
            0  => '上',
            1  => '下',
            2  => '左',
            3  => '右',
            4  => '左上',
            5  => '左下',
            6  => '右上',
            7  => '右下',
            8  => '放大',
            9  => '缩小',
            10 => '近焦距',
            11 => '远焦距',
        ];
    /**
     * @var null
     */
    public $hockAfterSend = null;


    private $logger = null;

    private $biz = null;

    /**
     *
     * OpenYs7 constructor.
     * @param array $params
     */
    public function __construct($params = [], $debug = true)
    {
        $this->debug = $debug;
        $this->appKey = $params['appKey'];
        if (empty($this->appKey))
            throw new Exception("muse be hav appKey");
        $this->appSecret = $params['appSecret'];
        if (empty($this->appSecret))
            throw new Exception("muse be hav appSecret");
        !empty($params['tokenTtl']) && $this->tokenTtl = $params['tokenTtl'];
        !empty($params['module']) && $this->module = $params['module'];
        if ($this->debug) {
            $this->logger = new JsonLogger('openys7-sdk', $this->createStream());
        }
        $this->generateUrl();
    }

    public function setBiz(Bfw $biz)
    {
        $this->biz = $biz;
    }

    protected function createStream() : StreamHandler
    {
        $logFile = runtime_path() . '/logs/openYs7SDK/' . date('Ym') . '/' . date('d') . '.log';
        if (is_file($logFile)) {
            $fileSize = filesize($logFile);
            clearstatcache(true, $logFile);
            $fileSize > self::MAX_LOG_SIZE && unlink($logFile);
        }

        return new StreamHandler($logFile, Logger::DEBUG, true, 0755);
    }

    /**
     *
     * 更替module，默认的module为lapp
     * @param $module
     * @return $this
     */
    public function module($module)
    {
        $this->module = $module;
        $this->generateUrl();
        return $this;
    }

    /**
     * 生成接口前缀
     * @return string
     */
    private function generateUrl()
    {
        $this->apiUri .= strpos($this->module, '/') === 0 ? $this->module : '/' . $this->module;
        return rtrim($this->apiUri, '/');
    }

    /**
     * @param array $data
     * @return array
     */
    private function packageData($data = [])
    {
        $data = array_merge([
            'accessToken' => $this->getAccessToken(),
        ], $data);
        return $data;
    }

    /**
     *
     * TODO: 优化，自定义异常，外层拦截
     * @param $res
     * @return array|mixed
     * @throws Exception
     */
    private function parseResponse($res)
    {
        $data = $this->decodeResult($res);
        $this->afterSend($data);
        if (!$this->isOk($data['code'])) {
            throw new Exception($this->error($data['code'], $data['msg']));
        }

        return $data; //isset($data['data']) ? $data['data'] : $data;
    }
    //region 接口请求

    /**
     * @param $url
     * @param array $data
     * @return mixed
     */
    public function send($url, $data)
    {
        $this->beforeSend($url, $data);
        $res = $this->curl()->post($url, $data);
        $this->afterSend($res);
        return $res;
    }

    /**
     *
     * api before send
     * @param $url
     * @param $data
     */
    public function beforeSend($url, &$data)
    {
        if (isset($data['pageStart'])) {
            $data['pageStart'] += 0;
            $data['pageStart'] < 0 && $pageStart = 0;
        }
        if (isset($data['pageSize'])) {
            $data['pageSize'] += 0;
            $data['pageSize'] < 0 && $data['pageSize'] = 10;
            if ($data['pageSize'] > 200)
                throw new Exception("最大分页数:200");
        }
    }

    /**
     *
     * api after send
     * @param $res
     */
    public function afterSend($res)
    {
        if (empty($res)) {
            return;
        }
        is_string($res) && $res = $this->decodeResult($res);
        if ($res['code'] === '10002') {
            $this->refererToken();
        }

        $this->logger && $this->logger->info("api after send", $res ?? []);
        if ($this->hockAfterSend instanceof \Closure) {
            call_user_func_array($this->hockAfterSend, [$this, $res]);
        }
    }

    /**
     * 请求获取accessToken
     *
     * @return mixed
     * @throws Exception
     */
    public function getToken()
    {
        $url = $this->apiUri . '/token/get';
        $data = [
            'appKey'    => $this->appKey,
            'appSecret' => $this->appSecret,
        ];
        $res = $this->send($url, $data);
        $data = $this->parseResponse($res);
        $accessToken = $data['data']['accessToken'];
        $this->setAccessToken($accessToken);
        return $accessToken;
    }

    /**
     *
     * 获取设备列表
     * @param int $pageStart
     * @param int $pageSize
     */
    public function getDeviceList($pageStart = 0, $pageSize = 10)
    {
        $url = $this->apiUri . '/device/list';
        $res = $this->send($url, $this->packageData([
            'pageStart' => $pageStart,
            'pageSize'  => $pageSize,
        ]));
        $data = $this->parseResponse($res);
        return $data;
    }

    /**
     *
     * 获取单个设备信息
     * @param $deviceSerial
     */
    public function getDeviceInfo($deviceSerial)
    {
        $url = $this->apiUri . '/device/info';
        $res = $this->send($url, $this->packageData([
            'deviceSerial' => $deviceSerial,
        ]));

        return $this->parseResponse($res);
    }

    /**
     *
     * 摄像头列表
     * @param int $pageStart
     * @param int $pageSize
     * @return mixed
     * @throws Exception
     */
    public function getCameraList($pageStart = 0, $pageSize = 32)
    {
        $pageSize > 200 && $pageSize = 200;
        $url = $this->apiUri . '/camera/list';
        $res = $this->send($url, $this->packageData([
            'pageStart' => $pageStart,
            'pageSize'  => $pageSize,
        ]));
        $data = $this->parseResponse($res);
        return $data;
    }

    /**
     *
     * 获取指定摄像头的通道信息
     * @param string $deviceSerial
     * @return null|array
     */
    public function getCamera($deviceSerial)
    {
        $url = $this->apiUri . '/camera/list';
        $res = $this->send($url, $this->packageData([
            'deviceSerial' => $deviceSerial,
        ]));
        $data = $this->parseResponse($res);

        return $data;
    }

    /**
     *
     * 直播列表
     * @param int $pageStart
     * @param int $pageSize
     * @return mixed
     */
    public function getLiveList($pageStart = 0, $pageSize = 32)
    {
        $url = $this->apiUri . '/live/video/list';
        $res = $this->send($url, $this->packageData([
            'pageStart' => $pageStart,
            'pageSize'  => $pageSize,
        ]));
        $data = $this->parseResponse($res);
        return $data;
    }

    public function getCameraLiveUrl($deviceSerial, $otherParams = [])
    {
        $url = $this->apiUri . '/v2/live/address/get';
        $urlType = isset($otherParams['type']) ? $otherParams['type'] : 1;
        $result = $this->send($url, $this->packageData([
            'deviceSerial' => $deviceSerial,
            'channelNo'    => isset($otherParams['channelNo']) ? $otherParams['channelNo'] : 1,
            'protocol'     => isset($otherParams['protocol']) ? $otherParams['protocol'] : 2,
            'expireTime'   => isset($otherParams['expireTime']) ? $otherParams['expireTime'] : 3600 * 24 * 1,
            'quality'      => isset($otherParams['quality']) ? $otherParams['quality'] : 2,
            'type'         => $urlType,
        ]));
        $data = $this->parseResponse($result);
        if ((int)$urlType === 2) {
            //https://open.ys7.com/console/jssdk/pc.html?url=ezopen://open.ys7.com/E57625900/1.rec&accessToken=at.34k5cqek81bs3k8x1s5tfuu80ji95kr4-3rbet3x1tl-1b4elnm-wfi5rkdmz&themeId=pcRec&env=
            //https://open.ys7.com/console/jssdk/mobile.html?accessToken=at.34k5cqek81bs3k8x1s5tfuu80ji95kr4-3rbet3x1tl-1b4elnm-wfi5rkdmz&url=ezopen://open.ys7.com/E57625900/1.live&themeId=mobileLive&env=
            $data['playback_pc_iframe_url'] = sprintf("https://open.ys7.com/console/jssdk/pc.html?url=%s&accessToken=%s&themeId=pcRec&env=",
                $data['url'], $data['accessToken']);
            $data['playback_h5_iframe_url'] = sprintf("https://open.ys7.com/console/jssdk/mobile.html?accessToken=%s&url=%s&themeId=mobileLive&env=",
                $data['accessToken'], $data['url']);
        }

        return $data;
    }

    /**
     * 失效直播地址
     *
     * @param $deviceSerial
     * @param string $urlId
     * @param array $otherParams
     * @return array|mixed
     * @throws Exception
     */
    public function disableLiveUrl($deviceSerial, $urlId, $otherParams = [])
    {
        $url = $this->apiUri . '/v2/live/address/disable';
        $result = $this->send($url, $this->packageData([
            'deviceSerial' => $deviceSerial,
            'urlId'        => $urlId,
            'channelNo'    => isset($otherParams['channelNo']) ? $otherParams['channelNo'] : 1,
            'expireTime'   => isset($otherParams['expireTime']) ? $otherParams['expireTime'] : 3600 * 24 * 1,
        ]));

        $data = $this->parseResponse($result);
        return $data;
    }

    /**
     *
     * 开通直播功能
     * @param $source 直播源，[设备序列号]:[通道号],[设备序列号]:[通道号]的形式，例如427734222:1,423344555:3，均采用英文符号，限制50个
     * @return mixed
     */
    public function openCameraLive($source)
    {
        $url = $this->apiUri . '/live/video/open';
        $res = $this->send($url, $this->packageData([
            'source' => $source,
        ]));
        $data = $this->parseResponse($res);
        return $data;
    }

    /**
     *
     * 开始云台控制(对设备进行开始云台控制，开始云台控制之后必须先调用停止云台控制接口才能进行其他操作，包括其他方向的云台转动)
     * @param $deviceSerial null 设备序列号,存在英文字母的设备序列号，字母需为大写
     * @param int $channelNo null 通道号
     * @param int $direction null 操作命令
     * @param int $speed null 云台速度
     * @return array|mixed
     * @throws Exception
     */
    public function devicePtzStart($deviceSerial, $channelNo = 1, $direction = 0, $speed = 1)
    {
        if (!key_exists($direction, $this->directionMap)) {
            throw new Exception("不支持该指令");
        }
        $url = $this->apiUri . '/device/ptz/start';
        $speed < 1 && $speed = 1;
        $params = [
            'deviceSerial' => $deviceSerial,
            'channelNo'    => $channelNo,
            'direction'    => $direction,
            'speed'        => $speed,
        ];
        $res = $this->send($url, $this->packageData($params));
        $data = $this->parseResponse($res);

        return $this->isOk($data['code']);
    }

    /**
     *
     * 设备停止云台控制
     * @param $deviceSerial null 设备序列号,存在英文字母的设备序列号，字母需为大写
     * @param int $channelNo null 通道号
     * @param int $direction null 操作命令
     * @return array|mixed
     * @throws Exception
     */
    public function devicePtzStop($deviceSerial, $channelNo = 1, $direction = 0)
    {
        if (!key_exists($direction, $this->directionMap)) {
            throw new Exception("不支持该指令");
        }
        $url = $this->apiUri . '/device/ptz/stop';
        $res = $this->send($url, $this->packageData([
            'deviceSerial' => $deviceSerial,
            'channelNo'    => $channelNo,
            'direction'    => $direction,
        ]));
        $data = $this->parseResponse($res);

        return $this->isOk($data['code']);
    }

    /**
     *
     * 设备云台控制，开始云台控制之后必须先调用停止云台控制接口才能进行其他操作，包括其他方向的云台转动
     * @param $deviceSerial
     * @param int $channelNo
     * @param int $direction
     * @param int $speed
     * @return bool
     */
    public function deviceControl($deviceSerial, $channelNo = 1, $direction = 0, $speed = 1)
    {
        $result = $this->devicePtzStart($deviceSerial, $channelNo, $direction, $speed);
        if ($result) {
            return $this->devicePtzStop($deviceSerial, $channelNo, $direction);
        }
        return false;
    }

    /**
     *
     * 查询账号下流量消耗汇总
     * @return array|mixed
     */
    public function trafficUserTotal()
    {
        $url = $this->apiUri . '/traffic/user/total';
        $res = $this->send($url, $this->packageData());
        $data = $this->parseResponse($res);
        return $data;
    }

    /**
     * 获取账号下的所有告警消息列表
     *
     * @return array|mixed
     * @throws Exception
     */
    public function alarmList()
    {
        $url = $this->apiUri . '/alarm/list';
        $res = $this->send($url, $this->packageData());
        $data = $this->parseResponse($res);
        return $data;
    }

    /**
     * 设备抓拍图片
     * 抓拍设备当前画面，该接口仅适用于IPC或者关联IPC的DVR设备，该接口并非预览时的截图功能。海康型号设备可能不支持萤石协议抓拍功能，使用该接口可能返回不支持或者超时。该接口需要设备支持能力集：support_capture=1
     * @param string $deviceSerial 设备边码
     * @param integer $channelNo 设备通道号
     * @param int $quality 视频清晰度,0-流畅,1-高清(720P),2-FCIF,3-1080P,4-400w
     * @return array|mixed 抓拍后的图片路径，图片保存有效期为2小时
     * @throws Exception
     */
    public function cameraCapture($deviceSerial, $channelNo, $quality = 1)
    {
        $url = $this->apiUri . '/device/capture';
        $res = $this->send($url, $this->packageData([
            'deviceSerial' => $deviceSerial,
            'channelNo'    => $channelNo,
            'quality'      => $quality,
        ]));
        $data = $this->parseResponse($res);
        return $data;
    }
    //endregion

    /**
     * 请求是否成功
     * @param $code
     * @return bool
     */
    public function isOk($code)
    {
        return (string)$code === '200';
    }

    /**
     * 输出错误信息
     */
    public function error($code = -1, $msg = '')
    {
        empty($msg) && $msg = '未知错误';
        return isset($this->errorMap[$code]) ? $this->errorMap[$code] : $msg;
    }

    /**
     * curl 操作类
     * @return CurlRequest
     */
    protected function curl()
    {
        $bCurl = new CurlRequest();
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        return $bCurl->setOption(CURLOPT_HTTPHEADER, $headers)
            ->setOption(CURLOPT_CONNECTTIMEOUT, 60)
            ->setOption(CURLOPT_TIMEOUT, 120);
    }

    /**
     * redis 缓存类
     */
    public function redis()
    {
        return $this->biz->offsetGet('redis.api.cache');
    }

    public function refererToken()
    {
        $this->redis()->del($this->getAccessTokenCacheKey());
        return $this->getToken();
    }

    /**
     * 获取 缓存的accessToken
     * 过期则重新从接口获取
     * @return mixed|null|string
     */
    public function getAccessToken()
    {
        $token = $this->redis()->get($this->getAccessTokenCacheKey());
        if (empty($token)) {
            $token = $this->getToken();
        }
        return $token;
    }

    /**
     * 保存accessToken至redis
     * @param $token
     * @throws Exception
     */
    protected function setAccessToken($token)
    {
        if (!$this->redis()->setEx($this->getAccessTokenCacheKey(), $this->tokenTtl, $token)) {
            throw new Exception("redis set token failed!!!");
        }
    }


    /**
     * 解码接口数据
     * @param $result
     * @return mixed
     */
    protected function decodeResult($result)
    {
        return json_decode($result, true);
    }

    private function getAccessTokenCacheKey()
    {
        return self::ACCESS_TOKEN_KEY . ':' . $this->appKey;
    }
}