<?php

namespace CoreW\Business\DataFilters;

use support\utils\ArrayToolkit;
use support\utils\AssetHelper;

/**
 * Filter 基类
 *
 * @method static simpleData(array $item)
 * @method static publicData(array $item)
 * @method static authenticatedData(array $item)
 * @method static simpleList(array $list)
 * @method static authenticatedList(array $list)
 * @method static publicList(array $list)
 *
 * 支持：
 * - simple / public / authenticated 三种模式的字段过滤
 * - formatFields 字段格式化
 */
abstract class Filter
{
    const AUTHENTICATED_MODE = 'authenticated';
    const SIMPLE_MODE        = 'simple';
    const PUBLIC_MODE        = 'public';

    protected string $mode = self::PUBLIC_MODE;

    protected ?string $assetUri = null;

    protected bool $formatTime = true;

    /**
     * 新增字段格式化规则
     * ['price' => 'float', 'avatar' => 'url', 'attrs' => 'json']
     */
    protected array $formatFields = [];


    protected array $appendFields = [];


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

    /**
     * 主过滤器
     */
    public function filter(&$data)
    {
        if (!$data || !is_array($data)) {
            return null;
        }

        // 先处理时间字段
        if ($this->formatTime) {
            $this->defaultTimeFilter($data);
        }

        // 字段过滤
        $modeField = $this->mode . 'Fields';
        if (property_exists($this, $modeField) && is_array($this->{$modeField}) && !empty($fields)) {

            $fields = $this->{$modeField};
            $filtered = ArrayToolkit::parts($data, $fields);

            // 可选模式钩子，例如 simpleFields(&$data)
            if (method_exists($this, $modeField)) {
                $this->$modeField($filtered);
            }

            $data = $filtered;
        }

        // 字段格式化
        $this->processFormat($data);

        // 字段追加
        $this->processAppend($data);

        return $data;
    }

    /**
     * 批量处理列表
     */
    public function filtersList($dataSet): ?array
    {
        if (!$dataSet || !is_array($dataSet)) {
            return null;
        }

        if (isset($dataSet['data']) && isset($dataSet['paging'])) {
            foreach ($dataSet['data'] as &$item) {
                $this->filter($item);
            }
            return $dataSet;
        }

        foreach ($dataSet as &$item) {
            $this->filter($item);
        }

        return $dataSet;
    }

    /**
     * 批量处理（引用方式）
     */
    public function filters(&$dataSet)
    {
        if (!$dataSet || !is_array($dataSet)) {
            return;
        }

        if (isset($dataSet['data']) && isset($dataSet['paging'])) {
            foreach ($dataSet['data'] as &$item) {
                $this->filter($item);
            }
            return;
        }

        foreach ($dataSet as &$item) {
            $this->filter($item);
        }
    }

    protected function init(): void
    {
        $this->assetUri = AssetHelper::getUri();
    }

    /**
     * 默认时间格式化
     */
    private function defaultTimeFilter(&$data): void
    {
        foreach (['createdTime', 'updatedTime', 'created_time', 'updated_time'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                $data[$field] = date('c', $data[$field]);
            }
        }
    }

    /**
     * 格式化字段处理器
     */
    protected function processFormat(array &$data): void
    {
        foreach ($this->formatFields as $field => $type) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            // 例如 enum:statusText
            if (str_contains($type, ':')) {
                [$handler, $param] = explode(':', $type, 2);
                $method = 'format_' . $handler;
                if (method_exists($this, $method)) {
                    $data[$field] = $this->$method($value, $param);
                }
                continue;
            }

            // 常规格式化方法
            $method = 'format_' . $type;
            if (method_exists($this, $method)) {
                $data[$field] = $this->$method($value);
            }
        }
    }

    protected function processAppend(array &$data): void
    {
        foreach ($this->appendFields as $newField => $handler) {
            // enum:xxx
            if (str_contains($handler, ':')) {
                [$type, $param] = explode(':', $handler, 2);
                $method = 'append_' . $type;

                if (method_exists($this, $method)) {
                    $data[$newField] = $this->$method($data, $param);
                }
                continue;
            }

            // 自动匹配 append_xxx
            $autoMethod = 'append_' . $handler;
            if (method_exists($this, $autoMethod)) {
                $data[$newField] = $this->$autoMethod($data);
                continue;
            }

            // 自定义方法
            if (method_exists($this, $handler)) {
                $data[$newField] = $this->$handler($data);
                continue;
            }
        }
    }

    protected function append_enum(array $data, string $method)
    {
        $value = $data[$method] ?? null;
        return method_exists($this, $method)
            ? $this->$method($value)
            : $value;
    }

    protected function append_json(array $data, string $field)
    {
        $value = $data[$field] ?? null;
        return is_string($value) ? json_decode($value, true) : $value;
    }

    /**
     * 基础格式化方法
     */
    protected function format_float($value)
    {
        return (float)$value;
    }

    protected function format_int($value)
    {
        return (int)$value;
    }

    protected function format_json($value)
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    protected function format_url($value)
    {
        return $this->convertFilePath($value);
    }

    protected function format_datetime(int $value): string
    {
        return $value === 0 ? '' : date('Y-m-d H:i:s', $value);
    }

    protected function format_enum($value, $mapMethod)
    {
        if (method_exists($this, $mapMethod)) {
            return $this->$mapMethod($value);
        }
        return $value;
    }

    protected function convertAbsoluteUrl($html): array|string|null
    {
        $filter = $this;
        return preg_replace_callback('/src=[\'\"]\/(.*?)[\'\"]/', function ($matches) use ($filter) {
            if (strpos($matches[1], '//') === 0) {
                return "src=\"/{$matches[1]}\"";
            }
            $path = '/' . ltrim($matches[1], '/');
            $url  = $filter->uriForPath($path);
            return "src=\"{$url}\"";
        }, $html);
    }

    protected function convertFilePath($filePath)
    {
        return $this->uriForPath('/' . ltrim($filePath, '/'));
    }

    protected function uriForPath($path)
    {
        $uri = \Request()->uri();
        return $uri . $path;
    }

    /**
     * 静态快捷调用
     */
    public static function __callStatic($name, $arguments)
    {
        $data = $arguments[0] ?? null;

        switch ($name) {
            case 'simpleData':
                return (new static(self::SIMPLE_MODE))->filter($data);

            case 'publicData':
                return (new static(self::PUBLIC_MODE))->filter($data);

            case 'authenticatedData':
                return (new static(self::AUTHENTICATED_MODE))->filter($data);

            case 'simpleList':
                return (new static(self::SIMPLE_MODE))->filtersList($data);

            case 'publicList':
                return (new static(self::PUBLIC_MODE))->filtersList($data);

            case 'authenticatedList':
                return (new static(self::AUTHENTICATED_MODE))->filtersList($data);
        }

        throw new \BadMethodCallException("Method {$name} not supported.");
    }
}

