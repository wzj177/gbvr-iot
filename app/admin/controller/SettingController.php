<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\BizEnum;
use CoreW\Sdk\ZLMediaKit\MediaServer;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Request;
use support\utils\ArrayToolkit;
use support\utils\AssetHelper;
use support\utils\SimpleValidator;
use Respect\Validation\Validator as v;

class SettingController extends BaseController
{

    public function attachmentOptions(Request $request)
    {
        return $this->createSuccessJsonResponse([
            'imageTypeOptions' => ArrayToolkit::enumToList(BizEnum::getAttachmentImageTypeItems()),
            'audioTypeOptions' => ArrayToolkit::enumToList(BizEnum::getAttachmentAudioTypeItems()),
            'videoTypeOptions' => ArrayToolkit::enumToList(BizEnum::getAttachmentVideoTypeItems()),
            'clipTypeOptions' => ArrayToolkit::enumToList(BizEnum::getAttachmentImageClipTypeItems())
        ]);
    }

    public function getSecure(Request $request)
    {
        return $this->createSuccessJsonResponse($this->getSettingService()->get('secure', null));
    }

    public function view(Request $request, $key)
    {
        $data = $this->getSettingService()->get($key, null);
        if ($key === 'basic' && !empty($data)) {
            $data['site_logo_full'] = !empty($data['site_logo']) ? AssetHelper::getUploadUrl($data['site_logo']) : '';
        }

        if ($key === 'attachment' && !empty($data['allow_file_exts']) && is_array($data['allow_file_exts'])) {
            $data['allow_file_exts'] = implode('|', $data['allow_file_exts']);
        }

        return $this->createSuccessJsonResponse($data);
    }

    public function setAuth(Request $request)
    {
        $fields = v::input($request->post(), [
            'user_password_level' => v::in(array_keys(BizEnum::getUserPwdLevelItems()))->setName('密码级别'),
            'login_connect_login_limit' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('登录限制'),
            'login_connect_client_login_limit' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('设备终端登录限制'),
            'login_captcha' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('登录验证码限制'),
            'login_sms' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('短信登录'),
            'temporary_lock_enabled' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('用户登录保护'),
            'oauth_login_enabled' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('第三方登录'),
        ]);
        if ($this->getSettingService()->set('auth', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setAttachment(Request $request)
    {
        $fields = v::input($request->post(), [
            'allow_image_exts' => v::callback(function ($value) {
                $intersection = array_intersect($value, array_keys(BizEnum::getAttachmentImageTypeItems()));

                return count($intersection);
            })->setName('图片类型'),
            'allow_image_upload_size' => v::intVal()->setName('图片大小限制(单位：KB)'),
            'allow_image_clip' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('图片裁剪'),
            'allow_snippet_upload' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('分片上传'),
            'allow_transcode_video' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('视频格式转化'),
            'image_clip_size_type' => v::callback(function ($value) {
                if (empty($value) || count($value) === 0) {
                    return true;
                }

                $intersection = array_intersect($value, array_keys(BizEnum::getAttachmentImageClipTypeItems()));

                return count($intersection);
            })->setName('图片裁剪尺寸'),
            'allow_audio_exts' => v::callback(function ($value) {
                $intersection = array_intersect($value, array_keys(BizEnum::getAttachmentAudioTypeItems()));

                return count($intersection);
            })->setName('音频类型'),
            'allow_audio_upload_size' => v::intVal()->setName('音频大小限制(单位：KB)'),
            'allow_video_exts' => v::callback(function ($value) {
                $intersection = array_intersect($value, array_keys(BizEnum::getAttachmentVideoTypeItems()));

                return count($intersection);
            })->setName('视频类型'),

            'allow_video_upload_size' => v::intVal()->setName('音频大小限制(单位：KB)'),
            'allow_file_exts' => v::stringVal()->setName('其它文件类型'),
            'allow_file_upload_size' => v::intVal()->setName('其它文件大小限制(单位：KB)'),
        ]);

        if (!empty($fields['allow_file_exts']) && is_string($fields['allow_file_exts'])) {
            $fields['allow_file_exts'] = explode('|', $fields['allow_file_exts']);
        }

        if ($this->getSettingService()->set('attachment', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setVIP(Request $request)
    {
        $fields = v::input($request->post(), [
            'enable_guest_view' => v::in(array_keys(BizEnum::getStorageTypeItems()))->setName('允许游客访问'),
            'enable_vip_diy' => v::in(array_keys(BizEnum::getStorageTypeItems()))->setName('允许会员DIY作品'),
        ]);

        if ($this->getSettingService()->set('vip', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setIPCheckList(Request $request)
    {
        $blackListIps = $request->post('blackListIps');
        $whiteListIps = $request->post('whiteListIps');
        $purifiedBlackIps = trim(preg_replace('/s+/', ' ', $blackListIps));
        $purifiedWhiteIps = trim(preg_replace('/s+/', ' ', $whiteListIps));
        if (empty($purifiedBlackIps)) {
            $this->getSettingService()->delete('blacklist_ip');
            $purifiedBlackIps = [];
        } else {
            $purifiedBlackIps = array_filter(explode("\n", $purifiedBlackIps), function ($ip) {
                return SimpleValidator::wildcardIP($ip);
            });
            $this->getSettingService()->set('blacklist_ip', $purifiedBlackIps);
        }
        if (empty($purifiedWhiteIps)) {
            $this->getSettingService()->delete('whitelist_ip');
            $purifiedWhiteIps = [];
        } else {
            $purifiedWhiteIps = array_filter(explode("\n", $purifiedWhiteIps), function ($ip) {
                return SimpleValidator::wildcardIP($ip);
            });
            $this->getSettingService()->set('whitelist_ip', $purifiedWhiteIps);
        }

        $this->getLogService()->info('system', 'update_settings', '更新IP黑名单/白名单', [
            'blacklist_ip' => $purifiedBlackIps,
            'whitelist_ip' => $purifiedWhiteIps,
            'currentIp' => $request->getRealIp()
        ]);

        return $this->createSuccessJsonResponse(null, '设置成功');
    }

    public function setCDN(Request $request)
    {
        $fields = $request->post();
        if (empty($fields['cdn_url'])) {
            $fields['cdn_url'] = '';
        }

        $fields = v::input($fields, [
            'cdn_enabled' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('启用状态'),
            'cdn_url' => v::stringVal()
        ]);

        if ($this->getSettingService()->set('cdn', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setStorage(Request $request)
    {
        $data = $fields = $request->post();
        $fields = v::input($fields, [
            'type' => v::in(array_keys(BizEnum::getStorageTypeItems()))->setName('存储类型'),
            'qiniu_access_key' => v::stringVal(),
            'qiniu_secret_key' => v::stringVal(),
            'qiniu_bucket' => v::stringVal(),
            'qiniu_url' => v::stringVal(),
            'ali_access_key' => v::stringVal(),
            'ali_secret_key' => v::stringVal(),
            'ali_bucket' => v::stringVal(),
            'ali_url' => v::stringVal(),
            'tencent_app_id' => v::stringVal(),
            'tencent_app_sercet' => v::stringVal(),
            'tencent_seret_key' => v::stringVal(),
            'tencent_bucket' => v::stringVal(),
            'tencent_bucket_location' => v::stringVal(),
            'tencent_url' => v::stringVal(),
        ]);

        switch ($fields['type']) {
            case BizEnum::STORAGE_TYPE_QINIU:
                $fields = v::input($fields, [
                    'qiniu_access_key' => v::notEmpty()->setName('七牛云AccessKey'),
                    'qiniu_secret_key' => v::notEmpty()->setName('七牛云SecretKey'),
                    'qiniu_bucket' => v::notEmpty()->setName('七牛云存储空间'),
                    'qiniu_url' => v::url()->setName('七牛云服务地址'),
                ]);
                break;
            case BizEnum::STORAGE_TYPE_ALI:
                $fields = v::input($fields, [
                    'ali_access_key' => v::notEmpty()->setName('阿里云Access Key ID'),
                    'ali_secret_key' => v::notEmpty()->setName('阿里云Access Key Secret'),
                    'ali_bucket' => v::notEmpty()->setName('阿里云存储空间'),
                    'ali_url' => v::url()->setName('阿里云服务地址'),
                ]);
                break;
            case BizEnum::STORAGE_TYPE_TENCENT:
                $fields = v::input($fields, [
                    'tencent_app_id' => v::notEmpty()->setName('腾讯云App ID'),
                    'tencent_app_sercet' => v::notEmpty()->setName('腾讯云Secret ID'),
                    'tencent_seret_key' => v::notEmpty()->setName('腾讯云Secret Key'),
                    'tencent_bucket' => v::notEmpty()->setName('腾讯云存储空间'),
                    'tencent_bucket_location' => v::notEmpty()->setName('腾讯云存储地域'),
                    'tencent_url' => v::url()->setName('腾讯云服务地址'),
                ]);
                break;
        }

        if ($this->getSettingService()->set('storage', $data)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setMail(Request $request)
    {
        $fields = $request->post();


        $fields = v::input($fields, [
            'enabled' => v::in(array_keys(BizEnum::getEnableOrDisableItems()))->setName('开启邮件发送状态'),
            'host' => v::notEmpty()->setName('服务器地址'),
            'port' => v::intVal()->setName('服务器端口'),
            'username' => v::email()->setName('身份验证用户名'),
            'password' => v::notEmpty()->setName('身份验证密码'),
            'from' => v::email()->setName('发信人邮件地址')
        ]);

        if (empty($fields['from']) || !SimpleValidator::email($fields['from'])) {
            return $this->createErrorJsonResponse('邮箱格式错误');
        }
        if (empty($fields['name'])) {
            $basicSetting = $this->getSettingService()->get('basic', []);
            if (!empty($basicSetting['site_name'])) {
                $fields['name'] = $basicSetting['site_name'];
            } else if (config('app.name')) {
                $fields['name'] = config('app.name');
            }
        }

        if ($this->getSettingService()->set('mail', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setBasic(Request $request)
    {
        $fields = v::input($request->post(), [
            'site_name' => v::length(2, 64)->setName('平台名称'),
            'site_url' => v::url()->setName('平台地址'),
            'site_logo' => v::stringVal(),
            'icp' => v::stringVal(),
        ]);
        if ($this->getSettingService()->set('basic', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    public function setVR(Request $request)
    {
        $fields = v::input($request->post(), [
           'chunk_tiles_size' => v::intVal()->setName('可分片全景图的大小')
        ]);

        if ($this->getSettingService()->set('vr', $fields)) {
            return $this->createSuccessJsonResponse(null, '设置成功');
        }

        return $this->createErrorJsonResponse('设置失败');
    }

    /**
     * @deprecated
     * @param Request $request
     * @return \support\Response
     */
    public function getZLM(Request $request)
    {
        return $this->createErrorJsonResponse('');
//        $zlmConfigFile = config_path('zlm/config.ini');
//        if (!file_exists($zlmConfigFile)) {
//            return $this->createErrorJsonResponse('ZLM配置文件不存在');
//        }
//
//        try {
//            $zlmConfig = parse_mit_ini($zlmConfigFile);
//
//            return $this->createSuccessJsonResponse($zlmConfig);
//
//        } catch (\Exception $e) {
//            $result = $this->getZlmClient()->getServerConfig();
//
//            if ($result['code'] === 0) {
//                $zlmConfig = $result['data'];
//
//                return $this->createSuccessJsonResponse($zlmConfig);
//            }
//
//            return $this->createErrorJsonResponse('ZLM 配置获取失败');
//        }
    }

    /**
     * @deprecated
     * @param Request $request
     * @return \support\Response
     */
    public function setZLM(Request $request)
    {
        // use respect/validation
        $zlmConfigFile = config_path('zlm/config.ini');
        if (!file_exists($zlmConfigFile)) {
            return $this->createErrorJsonResponse('ZLM配置文件不存在');
        }

        try {
            // 备份
            $zlmConfigFileBackup = $zlmConfigFile . '.bak';
            if (file_exists($zlmConfigFileBackup)) {
                @unlink($zlmConfigFileBackup);
            }
            @copy($zlmConfigFile, $zlmConfigFileBackup);
//         更新ZLM配置文件
            $this->getZlmClient()->setServerConfig($request->post());

            // TODO:后期建议对接supervisord 这里重启对应的服务
            $webman = base_path() . '/webman';
            if (file_exists($webman)) {
                // find php
                $php = shell_exec("which php");
                $php = trim($php);
                $cmd = "nohup {$php} {$webman} zlm:start -f > /dev/null 2>&1 &";
                shell_exec($cmd);  // 需要执行命令
            }

            return $this->createSuccessJsonResponse(null, '设置成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * @deprecated
     * @param Request $request
     * @return \support\Response
     */
    public function resetZLM(Request $request)
    {
        $zlmConfigFile = config_path('zlm/config.ini');
        if (!file_exists($zlmConfigFile)) {
            return $this->createErrorJsonResponse('ZLM配置文件不存在');
        }

        $zlmConfigFileBackup = $zlmConfigFile . '.bak';
        if (!file_exists($zlmConfigFileBackup)) {
            return $this->createErrorJsonResponse('ZLM配置无变化，无需重置');
        }

        try {
            @copy($zlmConfigFileBackup, $zlmConfigFile);
            @unlink($zlmConfigFileBackup);
            $this->getZlmClient()->restartServer();

            return $this->createSuccessJsonResponse(null, '重置成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * @return ZLMClient
     */
    protected function getZlmClient(): ZLMClient
    {
        return $this->getBiz()->offsetGet('zlm_sdk');
    }
}
