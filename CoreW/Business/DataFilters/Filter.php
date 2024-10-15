<?php

namespace CoreW\Business\DataFilters;

use support\utils\ArrayToolkit;
use support\utils\AssetHelper;

/**
 * @package CoreW\Business\DataFilters
 * @author  YangYang
 * @date    2024-08-14
 * @method  simpleData($data)
 * @method  publicData ($data)
 * @method  simpleList($list)
 * @method  publicList($list)
 */
abstract class Filter
{
    /**
     * 认证模式,返回用户登录后可访问的字段
     */
    const AUTHENTICATED_MODE = 'authenticated';

    /**
     * 简化模式,只返回少量的非隐私字段
     */
    const SIMPLE_MODE = 'simple';

    /**
     * 公开模式,返回未登录用户可访问的字段
     */
    const PUBLIC_MODE = 'public';


    protected $mode = self::PUBLIC_MODE;

    protected $assetUri = null;

    protected $formatTime = true;


    public function __construct($mode = 'public', bool $formatTime = true)
    {
        $this->setMode($mode);
        $this->formatTime = $formatTime;
        $this->init();
    }

    public function setMode($mode)
    {
        $this->mode = $mode;
    }

    public function filter(&$data)
    {
        if (!$data || !is_array($data)) {
            return null;
        }


        if ($this->formatTime) {
            $this->defaultTimeFilter($data);
        }

        $filteredData = [];
        $modes = [self::SIMPLE_MODE, self::PUBLIC_MODE, self::AUTHENTICATED_MODE];
        $modes = array_filter($modes, function ($mode) {
            return $mode === $this->mode;
        });

        foreach ($modes as $mode) {
            $property = $mode . 'Fields';
            if (property_exists($this, $property) && $this->{$property}) {
                $partData = ArrayToolkit::parts($data, $this->$property);
                if (method_exists($this, $property)) {
                    $this->$property($partData);
                }
                $filteredData = $partData;
            }
        }

        if ($filteredData) {
            $data = $filteredData;
        }

        return $data;
    }

    public function filtersList($dataSet): ?array
    {
        if (!$dataSet || !is_array($dataSet)) {
            return null;
        }

        if (array_key_exists('data', $dataSet) && array_key_exists('paging', $dataSet)) {
            foreach ($dataSet['data'] as &$data) {
                $dataSet['data'] = $this->filter($data);
            }
        } else {
            foreach ($dataSet as &$data) {
                $dataSet['data'] = $this->filter($data);
            }
        }

        return $dataSet;
    }

    public function filters(&$dataSet)
    {
        if (!$dataSet || !is_array($dataSet)) {
            return;
        }

        if (array_key_exists('data', $dataSet) && array_key_exists('paging', $dataSet)) {
            foreach ($dataSet['data'] as &$data) {
                $this->filter($data);
            }
        } else {
            foreach ($dataSet as &$data) {
                $this->filter($data);
            }
        }
    }

    protected function init()
    {
        $this->assetUri = AssetHelper::getUri();
    }

    private function defaultTimeFilter(&$data)
    {
        if (isset($data['createdTime']) && is_numeric($data['createdTime'])) {
            $data['createdTime'] = date('c', $data['createdTime']);
        }

        if (isset($data['updatedTime']) && is_numeric($data['updatedTime'])) {
            $data['updatedTime'] = date('c', $data['updatedTime']);
        }

        if (isset($data['created_time']) && is_numeric($data['created_time'])) {
            $data['created_time'] = date('c', $data['created_time']);
        }

        if (isset($data['updated_time']) && is_numeric($data['updated_time'])) {
            $data['updated_time'] = date('c', $data['updated_time']);
        }

    }

    protected function convertAbsoluteUrl($html)
    {
        $filter = $this;
        $html = preg_replace_callback('/src=[\'\"]\/(.*?)[\'\"]/', function ($matches) use ($filter) {
            // 因为众多路径放进了带有域名的URL，所以包含`//`的url一律按照不做处理
            if (0 === strpos($matches[1], '/')) {
                return "src=\"\/{$matches[1]}\"";
            }

            // @todo cdn 全局替换
            $path = '/' . ltrim($matches[1], '/');
            $absoluteUrl = $filter->uriForPath($path);

            return "src=\"{$absoluteUrl}\"";
        }, $html);

        return $html;
    }

    protected function convertFilePath($filePath)
    {
//        $cdn = new CdnUrl();
//        $cdnUrl = $cdn->get('content');
//        if (!empty($cdnUrl)) {
//            $url = AssetHelper::getScheme().':'.rtrim($cdnUrl, '/').'/'.ltrim($filePath, '/');
//        } else {
//            $url = $this->uriForPath('/'.ltrim($filePath, '/'));
//        }
        $url = $this->uriForPath('/' . ltrim($filePath, '/'));

        return $url;
    }

    protected function uriForPath($path)
    {
        $uri = \Request()->uri();

        return $uri . $path;
    }

    public static function __callStatic($name, $arguments)
    {
        if ($name === 'simpleData') {
            $filter = new static(self::SIMPLE_MODE);
            $data = $arguments[0] ?? null;
            return $filter->filter($data);

        } else if ($name === 'publicData') {
            $filter = new static(self::PUBLIC_MODE);
            $data = $arguments[0] ?? null;
            return $filter->filter($data);
        } else if ($name === 'simpleList') {
            $filter = new static(self::SIMPLE_MODE);
            $data = $arguments[0] ?? null;
            return $filter->filtersList($data);
        } else if ($name === 'publicList') {
            $filter = new static(self::PUBLIC_MODE);
            $data = $arguments[0] ?? null;
            return $filter->filtersList($data);
        }
    }
}