<?php

namespace CoreW\Business\Attachment\Dto;

use Webman\Http\UploadFile;

class FileUploadDto
{
    /**
     * 上传端：backend=后端，front=前端
     * @var string
     */
    public string $client;

    /**
     * 上传类型
     * @var string
     */
    public string $type;

    /** 单文件上传传入
     * @var UploadFile|null
     */
    public ?UploadFile $file;

    /**
     *  网络资源地址提取传入
     * @var string|null
     */
    public ?string $url = null;

    /**
     * base64 图片上传
     *
     * @var string|null
     */
    public ?string $base64Str = null;

    /**
     * 多文件上传传入
     * @var array|array[]|UploadFile[]
     */
    public array $files = [];


    /**
     * 资源组
     * @var string
     */
    public string $group;

    /**
     * 资源保存路径
     * @var string|null
     */
    public ?string $path = null;

    /**
     * 资源名
     * @var string|null
     */
    public ?string $name = null;

    /**
     * 会员ID或管理员ID
     * @var int|null
     */
    public ?int $userId = null;


    /**
     * 图片是否保存裁剪缩略图
     * @var bool
     */
    public bool $isSaveThumbImage = false;

    /**
     * 裁剪所需参数
     *
     * @var array|null
     */
    public ?array $thumbImageOptions = [];


    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}