<?php

namespace CoreW\Business\Attachment\Service;

use CoreW\Business\Attachment\Dto\FileUploadDto;
use Webman\Http\UploadFile;

interface AttachmentService
{
    public function getAttachmentById($id, bool $map = true);

    public function getAttachmentByHashId(string $hashId, bool $map = false);

    public function countAttachments(array $conditions);

    public function searchAttachments(array $conditions, array $orderBys, $start, $limit, $columns = []);


    public function updateAttachment($id, array $fields);

    /**
     * 删除单个文件
     *
     * @param $id
     * @param bool $unlink 删除真实文件标识
     * @return mixed
     */
    public function deleteAttachmentById($id, $unlink = true);

    /**
     * 删除附件文件
     *
     * @param $storage
     * @param $path
     * @param $uploadPath
     * @return bool
     */
    public function unlinkFile($storage, $path, $uploadPath = null) : bool;

    /**
     *
     * 批量删除文件
     * @param $ids
     * @param bool $unlin k删除真实文件标识
     * @return mixed
     */
    public function deleteFilesByIds($ids, $unlink = true);

    public function uploadFile(FileUploadDto $dto);


    public function uploadFiles(FileUploadDto $dto);

    public function uploadBase64Image(FileUploadDto $dto);

    public function uploadRemoteFile(FileUploadDto $dto);

    /**
     * 获取切片文件
     *
     * @param string $hash
     * @param boolean $makeDir
     * @return array
     */
    public function getChunkFilesByHashID(string $hash, $makeDir = true);

    /**
     * 分片文件上传
     *
     * @param UploadFile $file
     * @param string $hash
     * @param int $index
     * @param string $filename
     * @return mixed
     */
    public function uploadSnippet(UploadFile $file, string $hash, int $index, string $filename);

    /**
     * 合并分片文件
     * @param array $fields
     * @return mixed
     */
    public function mergeSnippetFile(array $fields);

    /**
     *
     * 合并临时文件
     * @param $chunkFile
     * @param $hash
     * @return bool
     */
    public function mergeTmpFile($chunkFile, $hash);

    /**
     * 附件移动分组
     *
     * @param $ids 附件id 项
     * @param $groupCode 分组编码
     * @return mixed
     */
    public function moveGroup($ids, $groupCode);
}
