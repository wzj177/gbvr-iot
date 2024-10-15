<?php


namespace CoreW\Business\Attachment\Implementors\Impl;


use CoreW\Business\Attachment\Implementors\FileImplementor;
use Webman\Http\UploadFile;

class TenCentFileImplementor implements FileImplementor
{

    /**
     *
     * 获取文件
     * @param null|array $file upload_files 表数据 可以是model对象 可以是数组
     * @return mixed|array|null
     */
    public function getFile($file)
    {
        // TODO: Implement getFile() method.
    }

    /**
     *
     * 文件上传存储
     * @param UploadFile $file
     * @param $path
     * @param $name
     * @param array $options
     * @return mixed
     */
    public function store(UploadFile $file, $path, $name = null, array $options = [])
    {
        // TODO: Implement store() method.
    }

    /**
     * base64流上传存储
     *
     * @param $base64Str
     * @param $path
     * @param null $name
     * @param array $options
     * @return mixed
     */
    public function storeBase64ImageFile($base64Str, $path, $name = null, array $options = [])
    {

    }

    /**
     * 远程文件上传存储
     *
     * @param $url
     * @param $path
     * @param null $name
     * @param array $options
     * @return mixed
     */
    public function storeRemoteFile($url, $path, $name = null, array $options = [])
    {

    }

    /**
     * 删除文件
     *
     * @param null|array $file
     * @return mixed|boolean
     */
    public function deleteFile($file)
    {
        // TODO: Implement deleteFile() method.
    }
}
