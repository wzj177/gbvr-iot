<?php

namespace CoreW\Business\Attachment\Service\Impl;

use CoreW\Business\Attachment\Dto\FileUploadDto;
use CoreW\Business\Attachment\Exception\AttachmentException;
use CoreW\Business\Attachment\Implementors\FileImplementor;
use CoreW\Business\Attachment\Implementors\FileImplementorFactory;
use CoreW\Business\Attachment\Service\AttachmentGroupService;
use CoreW\Business\BaseService;
use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Business\Attachment\Dao\AttachmentDao;
use CoreW\Business\BizEnum;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Exception\NotFoundException;
use Imagine\Image\Box;
use support\utils\ArrayToolkit;
use support\utils\AssetHelper;
use Webman\Http\UploadFile;
use Webman\RedisQueue\Client;

class AttachmentServiceImpl extends BaseService implements AttachmentService
{
    /**
     * 附件移动分组
     *
     * @param array $ids 附件id 项
     * @param string $groupCode 分组编码
     * @return mixed
     */
    public function moveGroup($ids, $groupCode)
    {
        if (empty($ids) || empty($groupCode)) {
            throw AttachmentException::MOVE_GROUP_PARAM_ERROR();
        }

        return $this->getAttachmentDao()->batchChangeGroupCode($ids, $groupCode);
    }


    /**
     * 获取附件详情
     *
     * @param $id
     * @param bool $map
     * @return mixed
     * @throws NotFoundException
     */
    public function getAttachmentById($id, bool $map = true)
    {
        $file = $this->getAttachmentDao()->get($id);
        if (empty($file)) {
            throw  new NotFoundException("附件不存在");
        }

        if (!$map) {
            return $file;
        }

        $group = $this->getAttachmentGroupService()->getAttachmentGroupByCode($file['groupCode']);

        return $this->mapFile($file, $group, AssetHelper::getUri());
    }

    public function getAttachmentByHashId(string $hashId, bool $map = false)
    {
        $file = $this->getAttachmentDao()->getByHashId($hashId);
        if (!$map) {
            return $file;
        }

        $group = $this->getAttachmentGroupService()->getAttachmentGroupByCode($file['groupCode']);

        return $this->mapFile($file, $group, AssetHelper::getUri());
    }

    /**
     * 获取附件总数
     *
     * @param array $conditions
     * @return mixed
     */
    public function countAttachments(array $conditions)
    {
        return $this->getAttachmentDao()->count($conditions);
    }

    /**
     *
     * 获取附件列表
     *
     * @param array $conditions
     * @param array $orderBys
     * @param $start
     * @param $limit
     * @param array $columns
     * @return mixed
     */
    public function searchAttachments(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        $files = $this->getAttachmentDao()->search($conditions, $orderBys, $start, $limit, $columns);
        $codes = ArrayToolkit::column($files, 'groupCode');
        $groups = $this->getAttachmentGroupService()->findAllByCodes($codes);
        $assetUri = AssetHelper::getUri();
        foreach ($files as &$file) {
            $group = isset($groups[$file['groupCode']]) ? $groups[$file['groupCode']] : null;
            $file = $this->mapFile($file, $group, $assetUri);
        }

        return $files;
    }


    /**
     * 更新附件信息
     *
     * @param $id
     * @param array $fields
     * @return mixed
     * @throws NotFoundException
     */
    public function updateAttachment($id, array $fields)
    {
//        $file = $this->getAttachmentById($id, false);
//        if (empty($file)) {
//            throw  new NotFoundException("附件不存在");
//        }
        $fields = ArrayToolkit::parts($fields, ['newFilename', 'videoCover', 'length', 'imgSize', 'transcodePath']);

        return $this->getAttachmentDao()->update($id, $fields);

    }

    /**
     * 删除附件
     *
     * @param $id
     * @param bool $unlink
     * @return mixed
     */
    public function deleteAttachmentById($id, $unlink = true)
    {
        $file = $this->getAttachmentById($id);
        if (empty($file)) {
            throw  new NotFoundException("附件不存在");
        }

        $res = $this->getAttachmentDao()->delete($id);
        if ($res && $unlink) {
            $this->unlinkFile($file['storage'], $file['filepath']);
        }

        return $res;
    }

    /**
     *
     * 批量删除文件
     * @param $ids
     * @param bool $unlin k删除真实文件标识
     * @return mixed
     */
    public function deleteFilesByIds($ids, $unlink = true)
    {
        if (empty($ids)) {
            throw AttachmentException::DELETE_FILE_PARAM_ERROR();
        }

        $files = $this->getAttachmentDao()->getAllByIds($ids);
        if (empty($files)) {
            throw AttachmentException::DELETE_FILE_PARAM_ERROR();
        }

        $pathList = ArrayToolkit::column($files, 'filepath');
        $res = $this->getAttachmentDao()->batchDelete(['ids' => $ids]);
        if ($unlink) {
            // TODO: 需要适配云存储
            Client::send('remove-attachment-file', [
                'paths' => $pathList,
            ]);
        }

        return $res;
    }

    /**
     * 通过文件 hashid获取分片文件
     *
     * @param string $hash
     * @param bool $makeDir
     * @return array
     */
    public function getChunkFilesByHashID(string $hash, $makeDir = true)
    {
        $chunkPath = $this->getUploadSnippetChunkPath() . DIRECTORY_SEPARATOR . $hash;
        if (!is_dir($chunkPath)) {
            if ($makeDir) {
                mkdir($chunkPath, 0777);
            }
            return [];
        }

        return \preg_find_dir_files($chunkPath, "{$hash}-*", true);
    }

    /**
     * 分片文件上传（仅用于本地存储）
     *
     * @param UploadFile $file
     * @param string $hash
     * @param int $index
     * @param string $filename
     * @return mixed
     */
    public function uploadSnippet(UploadFile $file, string $hash, int $index, string $filename)
    {
        if ($file == null) {
            throw AttachmentException::FILE_RESOURCE_EMPTY();
        }

        // 前端使用promise.all()发起请求，会偶现并发情况，导致判断is_dir()后mkdir出现报错
//        $lockFile = runtime_path('upload-snippet.lock');
//        $fp = fopen($lockFile, 'w'); // 打开锁文件
//        if (flock($fp, LOCK_EX)) { // 获取独占锁
//            try {
//                $chunkPath = $this->getUploadSnippetChunkPath() . DIRECTORY_SEPARATOR . $hash;
//                if (!is_dir($chunkPath)) {
//                    mkdir($chunkPath, 0777, true);
//                }
//            } catch (\Throwable $e) {
//                throw $e;
//            } finally {
//                flock($fp, LOCK_UN); // 释放锁
//            }
//        } else {
//            return false;
//        }
//
//        fclose($fp); // 关闭锁文件句柄
        $chunkPath = $this->getUploadSnippetChunkPath(false) . DIRECTORY_SEPARATOR . $hash;
        // 切片文件
        $chunkFilename = sprintf("%s/%s-%s", $chunkPath, $hash, $index);
        // 仅用于本地存储，对象存储使用官方提供的前端组件和接口适应大文件
        $file->move($chunkFilename);
//        $this->mergeTmpFile($chunkFilename, $hash);
//        $queue = 'make-upload-chunk-tmp-file';
//        Client::send($queue, [
//            'hash' => $hash,
//            'chunkFile' => $chunkFilename,
//            'filename' => $filename,
//            'chunkIndex' => $index
//        ]);

        return true;
    }

    /**
     * 合并分片文件
     *
     * 1、判断是否存在hash文件夹
     * 2、判断文件夹内的文件数量是否等于总切片数
     * 3、合并文件
     * 4、清空切片文件
     * @param array $fields
     * @return mixed
     */
    public function mergeSnippetFile(array $fields)
    {
        // total name hash create_user_id Client group
        $path = $this->getDefaultPath();
        $chunkDir = $this->getUploadSnippetChunkPath(false);
        $chunkPath = sprintf("%s/%s", $chunkDir, $fields['hash']);
        if (!is_dir($chunkPath)) {
            // 如果没有切片hash文件夹则表明上传失败
            throw AttachmentException::SNIPPET_UPLOAD_FILE_FAILED();
        }

        $info = explode('.', $fields['name']);
        $ext = strtolower(end($info));
        $fileDir = sprintf("%s/%s/%s", uploads_path(), $fields['group'], $path); // 上传文件上级目录
        $subPath = sprintf("%s/%s/%s.%s", $fields['group'], $path, $fields['hash'], $ext);
        $filepath = sprintf("%s/%s.%s", $fileDir, $fields['hash'], $ext);
        $subPath = AssetHelper::UPLOAD_FIX . $subPath;
        $localFileImplementor = $this->getFileImplementor('local');
        if (is_file($filepath)) {
            //  删除所有的临时文件
            $chunkFiles = $this->getChunkFilesByHashID($fields['hash']);
            $type = $localFileImplementor->getAttachmentTypeByExt($ext);
            $this->clearChunkFiles($chunkPath, $chunkFiles);
            $attachment = $this->getAttachmentDao()->getOneByStorageAndPath('local', $subPath);

            return $this->responseFormat($attachment ? $attachment['id'] : 0, $subPath, $type);
        }

        try {
            $chunkFiles = [];
            $tmpFile = $chunkPath . '/tmp';
//            echo 'tmp size:', filesize($tmpFile), '|file size:', $fields['size'], PHP_EOL;
            if (is_file($tmpFile) && filesize($tmpFile) === $fields['size']) {
                // 如果写入了临时文件（一边分片一边写入的情况），同时临时文件的大小==上传文件的大小
                if (!is_dir($fileDir)) {
                    @mkdir($fileDir, 0755, true);
                }

                $this->moveTmpFile($tmpFile, $filepath);

            } else {
                // 合并分片文件
                $chunkFiles = $this->getChunkFilesByHashID($fields['hash'], false);
                $chunkFileNum = count($chunkFiles);
                if (!$chunkFileNum || $chunkFileNum !== (int)$fields['total']) {
                    throw AttachmentException::SNIPPET_UPLOAD_FILE_CHUNK_NUM_FAILED();
                }

                if (!is_dir($fileDir)) {
                    @mkdir($fileDir, 0755, true);
                }

                $webman = base_path('webman');
                $cmd = "php {$webman} upload:merge-chunk {$fields['hash']} {$filepath}";
                shell_exec($cmd);
//                $fp = fopen($filepath, 'ab+');
//                foreach ($chunkFiles as $chunkFile) {
//                    $chunkFp = fopen($chunkFile, 'rb');
////                    stream_copy_to_stream($chunkFp, $fp);
//                    while (!feof($chunkFp)) {
//                        fwrite($fp, fread($chunkFp, 1024 * 1024 * 5));
//                    }
//                    fclose($chunkFp);
//                }
//
//                fclose($fp);
            }

            if (!is_file($filepath)) {
                throw AttachmentException::SNIPPET_UPLOAD_FILE_CHUNK_MERGE_FAILED();
            }

            $pathinfo = pathinfo($filepath);
            $type = $localFileImplementor->getAttachmentTypeByExt($pathinfo['extension']);
            $attachment = [
                'status' => 'ok',
                'createUserId' => $fields['create_user_id'],
                'createClient' => $fields['Client'],
                'storage' => 'local',
                'filename' => $fields['name'],
                'fileSize' => filesize($filepath),
                'groupCode' => $fields['group'],
                'filepath' => $subPath,
                'newFilename' => $pathinfo['filename'] . '.' . $ext,
                'ext' => $pathinfo['extension'],
                'metas' => '',
                'type' => $type,
                'hashId' => hash_file('sha256', $filepath)
            ];
            $row = $this->getAttachmentDao()->create($attachment);
            $this->getLogService()->info('attachment', 'upload', '分片上传成功', $fields);
            $this->clearChunkFiles($chunkPath, $chunkFiles);
            //  文件上传后异步处理（获取音视频时长、视频封面、图片大小、【转码】）
            Client::send('file-after-upload-process', ['file_id' => $row['id']]);

            return $this->responseFormat($row['id'], $subPath, $type);

        } catch (\Throwable $e) {
            $this->getLogService()->error('attachment', 'upload', '分片上传失败:' . $e->getMessage(), $fields);
            $this->clearChunkFiles($chunkPath, $chunkFiles);
            throw $e;
        }
    }

    /**
     * 列表返回格式化
     *
     * @param $file
     * @param $group
     * @param $assetUri
     * @return mixed
     */
    protected function mapFile($file, $group, $assetUri)
    {
        $file['groupTitle'] = '';
        if ($group) {
            $file['groupTitle'] = $group['title'];
        }

        $file['url'] = AssetHelper::getUploadUrl($file['filepath'], null, $assetUri);
        if ($file['type'] === BizEnum::FILE_TYPE_IMAGE) {
            $file['coverFull'] = $file['url'];
        } else {
            if (!empty($file['videoCover'])) {
                $file['coverFull'] = AssetHelper::getUploadUrl($file['videoCover'], null, $assetUri);
            } else {
                $file['coverFull'] = AssetHelper::getAssetUrl("images/default/attachment/{$file['type']}.png", $assetUri);
            }

        }

        return $file;
    }

    /**
     * 移动临时文件
     *
     * @param $tmpFile
     * @param $filepath
     * @return bool
     */
    protected function moveTmpFile($tmpFile, $filepath)
    {
        if (!\is_win_os()) {
            $command = "rsync -ah --progress '$tmpFile' '$filepath'";
//            echo $command, PHP_EOL;
            exec($command, $output, $returnCode);
            if ($returnCode === 0) {
                return true;
            }
        } else {
            $command = "robocopy '$tmpFile' '$filepath' /MOV";
            exec($command, $output, $returnCode);

            if ($returnCode === 1 || $returnCode === 0) {
                return true;
            }
        }

        copy($tmpFile, $filepath);
        return true;
    }

    /**
     *
     * 合并临时文件
     * @param $chunkFile
     * @param $hash
     * @return bool
     */
    public function mergeTmpFile($chunkFile, $hash)
    {
        // 这里需要处理逻辑：记录最后一次写入的分片号，如果当前的分片号<上次的分片号，则抛弃---需要使用循环
        $path = pathinfo($chunkFile, PATHINFO_DIRNAME);
        $tmpIndexFile = sprintf("%s/index_tmp", $path);
        if (!is_file($tmpIndexFile) && touch($tmpIndexFile)) {}
        $tmpIndexFp = fopen($tmpIndexFile, 'r');
        if (flock($tmpIndexFp, LOCK_EX)) {
            try {
                $tmpFile = sprintf("%s/tmp", $path);
                $chunkFiles = \preg_find_dir_files($path, "{$hash}-*", true);
                $body = file_get_contents($tmpIndexFile);
                $prevChunkIndexItems = empty($body) ? [] : explode('|', $body);
                for ($i = 1; $i <= count($chunkFiles); $i++) {
                    if (in_array($i, $prevChunkIndexItems)) {
                        continue;
                    }
                    $prevChunkIndexItems[] = $i;
                    $this->writeTmpFile($tmpFile, $chunkFiles[$i - 1]);
                    file_put_contents($tmpIndexFile, implode('|', $prevChunkIndexItems));
                    break;
                }

                $chunkFiles = [];
            } catch (\Throwable $e) {
                return false;
            } finally {
                flock($tmpIndexFp, LOCK_UN); // 释放锁
                fclose($tmpIndexFp);
            }
        }
    }

    /**
     * 写入临时文件
     *
     * @param $tmpFile
     * @param $chunkFile
     */
    protected function writeTmpFile($tmpFile, $chunkFile)
    {
        $fp = fopen($tmpFile, 'ab+');
        $chunkFp = fopen($chunkFile, 'rb');
        while (!feof($chunkFp)) {
            fwrite($fp, fread($chunkFp, 1024 * 1024 * 5));
        }

        fclose($chunkFp);
        fclose($fp);
    }

    /**
     * base64图片上传
     *
     * @param FileUploadDto $dto
     * @return array
     * @throws \Throwable
     */
    public function uploadBase64Image(FileUploadDto $dto)
    {
        try {
            empty($path) && $path = $this->getDefaultPath();
            $fields = $this->getSystemSettingFileImplementor()->storeBase64ImageFile($dto->base64Str, $path, $dto->name, [
                'group' => $dto->group
            ]);
            $fields['status'] = 'ok';
            $fields['createUserId'] = $dto->userId;
            $fields['createClient'] = $dto->client;

            $row = $this->getAttachmentDao()->create($fields);
            $this->getLogService()->info('attachment', 'upload', '上传成功', $fields);

            return $this->responseFormat($row['id'], $fields['filepath'], $fields['type']);
        } catch (\Throwable $e) {
            $this->getLogService()->error('attachment', 'upload', 'base64上传失败:' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 网络提取文件
     *
     * @param FileUploadDto $dto
     * @return array
     * @throws \Throwable
     */
    public function uploadRemoteFile(FileUploadDto $dto)
    {
        try {
            empty($path) && $path = $this->getDefaultPath();
            $fields = $this->getSystemSettingFileImplementor()->storeRemoteFile($dto->url, $path, $dto->name, [
                'group' => $dto->group
            ]);
            $fields['status'] = 'ok';
            $fields['createUserId'] = $dto->userId;
            $fields['createClient'] = $dto->client;

            $row = $this->getAttachmentDao()->create($fields);
            $this->getLogService()->info('attachment', 'upload', '上传成功', $fields);
            //  文件上传后异步处理（获取音视频时长、视频封面、图片大小、【转码】）
            Client::send('file-after-upload-process', ['file_id' => $row['id']]);
            return $this->responseFormat($row['id'], $fields['filepath'], $fields['type']);
        } catch (\Throwable $e) {
            $this->getLogService()->error('attachment', 'upload', '上传失败:' . $e->getMessage(), ['url' => $dto->url]);
            throw $e;
        }
    }


    /**
     * 上传单个文件
     * @param FileUploadDto $dto
     * @return array
     * @throws \Throwable
     */
    public function uploadFile(FileUploadDto $dto)
    {
        try {
            $fields = $this->storeFile($dto);
            if(isset($fields['firstStorage']) && $fields['firstStorage']) {
                unset($fields['firstStorage']);
                $attachment = $this->getAttachmentDao()->create($fields);
                $this->getLogService()->info('attachment', 'upload', '上传成功', $fields);
            } else {
                $attachment = $this->getAttachmentDao()->getByHashId($fields['hashId']);
            }

            //  文件上传后异步处理（获取音视频时长、视频封面、图片大小、【转码】）
            Client::send('file-after-upload-process', ['file_id' => $attachment['id']]);
            $file = $this->responseFormat($attachment['id'], $fields['filepath'], $fields['type']);
            $file['thumbPath'] = $fields['thumbPath'] ?? '';
            $file['thumbUrl'] = isset($fields['thumbPath']) ? AssetHelper::getUploadUrl($fields['thumbPath']) : '';

            return $file;
        } catch (\Throwable $e) {
            $this->getLogService()->error('attachment', 'upload', '上传失败:' . $e->getMessage(), ['fileName' => $dto->file->getUploadName()]);
            throw $e;
        }
    }

    /**
     * 多文件上传（eg：前端 files[])
     *
     * @param FileUploadDto $dto
     * @return array
     */
    public function uploadFiles(FileUploadDto $dto)
    {
        if (empty($dto->files)) {
            throw AttachmentException::MULTI_FILES_EMPTY();
        }

        $items = [];
        $assetUri = AssetHelper::getUri();
        foreach ($dto->files as $file) {
            $dto->file = $file;
            $fields = $this->storeFile($dto);
            $row = $this->getAttachmentDao()->create($fields);
            $this->getLogService()->info('attachment', 'upload', '上传成功', $fields);
            //  文件上传后异步处理（获取音视频时长、视频封面、图片大小、【转码】）
            Client::send('file-after-upload-process', ['file_id' => $row['id']]);
            $items[] = $this->responseFormat($row['id'], $fields['filepath'], $fields['type'], $assetUri);
//            try {
//                $fields = $this->storeFile($file, $group, $path, null, $userId, $Client);
//
//                $rows[] = $this->responseFormat($fields['filepath'], $fields['type'], $assetUri);
//            } catch (\Throwable $e) {
//                $this->getLogService()->error('attachment', 'upload', '上传失败:' . $e->getMessage(), ['fileName' => $file->getUploadName()]);
//                throw $e;
//            }
            //$this->getAttachmentDao()->batchCreate($rows)
        }

        return $items;
    }

    /**
     * 清空分片文件
     *
     * @param $chunkDir
     * @param $chunkFiles
     * @param bool $async
     * @return bool
     */
    protected function clearChunkFiles($chunkDir, $chunkFiles, $async = true)
    {
        if (!$async) {
            foreach ($chunkFiles as $file) {
                @unlink($file);
            }

            $tmpFile = sprintf('%s/tmp', $chunkDir);
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }

            @rmdir($chunkDir);
            return true;
        }

        Client::send('clear-upload-chunk-file', ['chunkFiles' => $chunkFiles, 'chunkDir' => $chunkDir]);

        return true;
    }

    /**
     * 统一上传返回数据结构
     *
     * @param $fileId
     * @param $filepath
     * @param $fileCatalog
     * @param null $uri
     * @return array
     */
    protected function responseFormat($fileId, $filepath, $fileCatalog, $uri = null)
    {
        $url = AssetHelper::getUploadUrl($filepath, null, $uri);

        return [
            'fileId' => $fileId,
            'path' => $filepath,
            'type' => $fileCatalog,
            'cover' => $fileCatalog === 'image' ? $url : AssetHelper::getAssetUrl("images/default/attachment/{$fileCatalog}.png"),
            'url' => $url
        ];
    }

    /**
     * 获取分片目录
     *
     * @param bool $checkAndMakeDir
     * @return array|mixed|null
     */
    protected function getUploadSnippetChunkPath($checkAndMakeDir = true)
    {
        $dir = config('app.upload_chunk_tmp_file');
        // 通过一个原子操作来判断

        if ($checkAndMakeDir && !is_dir($dir) && mkdir($dir, 0777, true)) {
        }

        return $dir;
    }


    /**
     * 上传文件
     * @param FileUploadDto $dto
     * @return array|string|UploadFile
     */
    protected function storeFile(FileUploadDto $dto)
    {
        if ($dto->file == null) {
            throw AttachmentException::FILE_RESOURCE_EMPTY();
        }

        $this->validateFile($dto->file);
        empty($dto->path) && $dto->path = $this->getDefaultPath();
        $fields = $this->getSystemSettingFileImplementor()->store($dto->file, $dto->path, $dto->name, [
            'group' => $dto->group,
            'isSaveThumbImage' => $dto->isSaveThumbImage,
            'thumbImageOptions' => $dto->thumbImageOptions,
        ]);
        $fields['status'] = 'ok';
        $fields['createUserId'] = $dto->userId;
        $fields['createClient'] = $dto->client;

        return $fields;
    }

    /**
     * 获取默认上传存储子目录
     *
     * @return string
     */
    protected function getDefaultPath()
    {
        return date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d');
    }

    /**
     * 文件上传校验
     *
     * @param UploadFile $file
     * @return bool|string
     */
    protected function validateFile(UploadFile $file)
    {
        $setting = $this->getSettingService()->get('attachment');
        if (empty($setting)) {
            return true;
        }

        $ext = strtolower($file->getUploadExtension());

        return \attachment_validate_upload_file($setting, $ext, $file->getSize());
    }

    /**
     * 删除附件文件
     *
     * @param $storage
     * @param $path
     * @param $uploadPath
     * @return bool
     */
    public function unlinkFile($storage, $path, $uploadPath = null): bool
    {
        if (empty($path)) {
            return false;
        }

        if ('local' === $storage) {
            empty($uploadPath) && $uploadPath = uploads_path();
            $filepath = $uploadPath . ltrim($path, 'uploads');
            if (!is_file($filepath)) {
                return false;
            }

            $dir = dirname($filepath);
            @unlink($filepath);
            $fileName = pathinfo($filepath, PATHINFO_FILENAME);
            $coverPath = sprintf("%s/%s_cover.jpg", $dir, $fileName);
            if (is_file($coverPath)) {
                @unlink($coverPath);
            }
            $transcodePath = sprintf("%s/%s_transcode.mp4", $dir, $fileName);
            if (is_file($transcodePath)) {
                @unlink($transcodePath);
            }
            $hlsDir = sprintf("%s/%s_hls", $dir, $fileName);
            if (is_dir($hlsDir)) {
                @shell_exec("rm -r {$hlsDir}");
            }

            return true;
        }

        // 七牛
        // 腾讯云

        return true;
    }

    /**
     * 获取系统设定的文件存储方式
     *
     * @return FileImplementor
     */
    protected function getSystemSettingFileImplementor()
    {
        $setting = $this->getSettingService()->get('attachment');

        $type = empty($setting['type']) ? 'local' : $setting['type'];
        unset($setting['type']);

        return $this->getFileImplementor($type, $setting);
    }

    /**
     * @param $type
     * @param array $options
     * @return FileImplementor
     */
    protected function getFileImplementor($type, array $options = [])
    {
        return FileImplementorFactory::make($type, $options);
    }


    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->createService('Setting:SettingService');
    }

    /**
     * @return SystemLogService
     */
    protected function getLogService()
    {
        return $this->createService('SystemLog:SystemLogService');
    }

    /**
     * @return AttachmentGroupService
     */
    protected function getAttachmentGroupService()
    {
        return $this->createService('Attachment:AttachmentGroupService');
    }

    /**
     * @return AttachmentDao
     */
    protected function getAttachmentDao()
    {
        return $this->createDao('Attachment:AttachmentDao');

    }
}
