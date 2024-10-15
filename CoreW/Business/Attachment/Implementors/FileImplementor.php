<?php


namespace CoreW\Business\Attachment\Implementors;


use Webman\Http\UploadFile;

/**
 * Interface FileImplementor
 *
 * @method getAttachmentTypeByExt($ext)
 * @method getAttachmentTypeByUploadFile($ext)
 * @method getAttachmentTypeByMimeTypeAndExt($ext)
 * @package CoreW\Business\Attachment\Implementors
 */
interface FileImplementor
{
    /**
     *
     * 获取文件
     * @param null|array $file upload_files 表数据 可以是model对象 可以是数组
     * @return mixed|array|null
     */
    public function getFile($file);

    /**
     * 文件上传存储
     *
     * @param UploadFile $file
     * @param $path
     * @param $name
     * @param array $options
     * @return false|UploadFile|string|array
     */
    public function store(UploadFile $file, $path, $name = null, array $options = []);

    /**
     * 删除文件
     *
     * @param null|array $file
     * @return mixed|boolean
     */
    public function deleteFile($file);

    /**
     * base64流上传存储
     *
     * @param $base64Str
     * @param $path
     * @param $name
     * @param $options
     * @return array|null
     */
    public function storeBase64ImageFile($base64Str, $path, $name = null, array $options = []);

    /**
     * 远程文件上传存储
     *
     * @param $url
     * @param $path
     * @param $name
     * @param $options
     * @return array|null
     */
    public function storeRemoteFile($url, $path, $name = null, array  $options = []);
}
