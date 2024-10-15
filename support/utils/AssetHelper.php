<?php


namespace support\utils;


use CoreW\Core;

class AssetHelper
{
    const UPLOAD_FIX = 'uploads/';

    /**
     * 获取绝对url（一般用于生成路由绝对地址）
     *
     * @param $path
     * @return string
     */
    public static function absoluteUrl($path)
    {
        try {
            $url = \Request()->uri();
        } catch (\Throwable $e) {
            $biz = Core::instance();
            $settingService = $biz->service('Setting:SettingService');
            $basicSetting = $settingService->get('basic', []);
            if (!empty($basicSetting['site_url'])) {
                $url = $basicSetting['site_url'];
            }
        }
        if (empty($url)) {
            throw new \Exception("site url is empty");
        }

        $url = rtrim($url, '/');
        $path = ltrim($path, '/');

        return $url . '/' . $path;
    }

    /**
     * 获取静态资源前缀url
     * TODO： 后期需要安装程序中加入读取当前url并写入到site_url里
     * @return string|null
     */
    public static function getUri()
    {
        $biz = Core::instance();
        $settingService = $biz->service('Setting:SettingService');
        $cdnSetting = $settingService->get('cdn', []);
        if (isset($cdnSetting['cdn_enabled']) && $cdnSetting['cdn_enabled'] == 1 && !empty($cdnSetting['cdn_url'])) {
            $url = $cdnSetting['cdn_url'];
        } else {
            $basicSetting = $settingService->get('basic', []);
            if (!empty($basicSetting['site_url'])) {
                $url = $basicSetting['site_url'];
            }
        }

        return $url ?? \Request()->uri();
    }

    /**
     *
     * 获取静态资源url
     *
     * @param string $path 资源路径
     * @param null $uri 资源uri
     * @return string
     */
    public static function getAssetUrl(string $path, $uri = null)
    {
        if (StringToolkit::is_valid_url($path)) {
            return $path;
        }

        if (empty($uri)) {
            $uri = self::getUri();
        }

        return $uri . "/static/{$path}";
    }

    /**
     * 获取上传资源url
     *
     * @param string $path 资源路径
     * @param null $defaultKey 默认图片（仅当为图片使用）
     * @param null $uri 自定义url；默认为系统配置站点地址或cdn地址
     * @return string
     */
    public static function getUploadUrl(string $path, $defaultKey = null, $uri = null)
    {
        if ( false !== strpos($path, 'http://') || false !== strpos($path, 'https://')) {
            return $path;
        }

        empty($uri) && $uri = self::getUri();
        $uri = rtrim($uri, '/') . '/';
        $getDefaultUrl = function () use ($defaultKey, $uri) {
            if (!empty($defaultKey)) {
                $file = \static_assets_path() . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . $defaultKey . '.png';
                if (is_file($file)) {
                    return $uri . "static/images/default/{$defaultKey}.png";
                }
            }

            return '';
        };

        if (empty($path)) {
            return $getDefaultUrl();
        }
        $tmp = $path;
        if (($index = strpos($path, self::UPLOAD_FIX)) === 0) {
            $tmp = substr($path, $index + strlen(self::UPLOAD_FIX));
        }

        $file = \uploads_path() . DIRECTORY_SEPARATOR . $tmp;
        if (is_file($file) || is_dir($file)) {
            return $uri . $path;
        }

        return $getDefaultUrl();
    }

    public static function uriForPath($path)
    {
        return \Request()->uri() . $path;
    }

    public static function getScheme()
    {
        return \Request()->host();
    }

    public static function uploadPath(string $path): string
    {
        return uploads_path() . DIRECTORY_SEPARATOR . str_replace('uploads/', '' , $path);
    }
}