<?php


namespace CoreW\Business\Attachment\Implementors\Impl;


use CoreW\Business\Attachment\Exception\AttachmentException;
use CoreW\Business\Attachment\Implementors\AbstractFileImplementor;
use CoreW\Business\Attachment\Implementors\FileImplementor;
use OSS\OssClient;
use OSS\Core\OssException;
use Webman\Http\UploadFile;

class AliFileImplementor extends AbstractFileImplementor implements FileImplementor
{
    /**
     * @var OssClient Alibaba Cloud OSS Client
     */
    protected $ossClient;

    /**
     * @var string Bucket name
     */
    protected $bucket;

    /**
     * @var string Bucket endpoint
     */
    protected $endpoint;

    /**
     * @var bool Use SSL/TLS
     */
    protected $useSsl;

    /**
     * @var bool Use CDN domain
     */
    protected $useCdn;

    /**
     * @var string CDN domain
     */
    protected $cdnDomain;

    /**
     * @var string Custom domain
     */
    protected $customDomain;

    /**
     * @var bool Is CName
     */
    protected $isCName;

    /**
     * Initialize Alibaba Cloud OSS client with configuration
     */
    public function __construct($config)
    {
        parent::__construct($config);

        $this->bucket = $config['bucket'] ?? '';
        $this->endpoint = $config['endpoint'] ?? '';
        $this->useSsl = $config['use_ssl'] ?? true;
        $this->useCdn = $config['use_cdn'] ?? false;
        $this->cdnDomain = $config['cdn_domain'] ?? '';
        $this->customDomain = $config['custom_domain'] ?? '';
        $this->isCName = $config['is_cname'] ?? false;

        $accessKeyId = $config['access_key_id'] ?? '';
        $accessKeySecret = $config['access_key_secret'] ?? '';

        if (empty($accessKeyId) || empty($accessKeySecret) || empty($this->bucket)) {
            throw AttachmentException::OSS_CONFIG_ERROR('阿里云OSS配置不完整，请检查access_key_id、access_key_secret和bucket');
        }

        try {
            $this->ossClient = new OssClient(
                $accessKeyId,
                $accessKeySecret,
                $this->endpoint,
                $this->isCName,
                null,
                $this->useSsl ? 'https' : 'http'
            );
        } catch (OssException $e) {
            throw AttachmentException::OSS_CONFIG_ERROR('阿里云OSS初始化失败: ' . $e->getMessage());
        }
    }

    /**
     * Get file access URL
     *
     * @param array $file Attachment record
     * @return string|null File URL
     */
    public function getFile($file)
    {
        if (empty($file)) {
            return null;
        }

        $object = $file['filepath'] ?? '';
        if (empty($object)) {
            return null;
        }

        // Remove 'uploads/' prefix if exists
        $object = ltrim($object, 'uploads/');

        return $this->getFileUrl($object);
    }

    /**
     * Store uploaded file to Alibaba Cloud OSS
     *
     * @param UploadFile $file
     * @param string $path
     * @param string|null $name
     * @param array $options
     * @return array
     */
    public function store(UploadFile $file, $path, $name = null, array $options = [])
    {
        $group = $options['group'] ?? 'default';
        $algo = $options['hash_algo'] ?? 'sha256';
        empty($name) && $name = time();

        if (!$file->isValid()) {
            throw AttachmentException::UPLOAD_TRANS_FAILED();
        }

        $ext = $file->getUploadExtension();
        $filename = $file->getUploadName();
        $fileSize = $file->getSize();
        $metas = $file->getUploadMimeType();
        $type = $this->getAttachmentTypeByUploadFile($file);

        // Generate object key
        $object = $this->generateObjectKey($group, $path, $name, $ext);

        // Calculate hash for deduplication
        $localFilePath = $file->getRealPath();
        $hashId = hash_file($algo, $localFilePath);

        // Check if file already exists by hash
        $existFile = $this->getFileByHashId($hashId);
        if (!empty($existFile)) {
            return [
                'storage'      => 'aliyun',
                'filename'     => $filename,
                'newFilename'  => $existFile['newFilename'],
                'ext'          => $existFile['ext'],
                'metas'        => $existFile['metas'],
                'fileSize'     => $existFile['fileSize'],
                'type'         => $existFile['type'],
                'filepath'     => $existFile['filepath'],
                'hashId'       => $hashId,
                'groupCode'    => $existFile['groupCode'],
                'globalId'     => $existFile['globalId'],
                'firstStorage' => false,
            ];
        }

        try {
            // Upload to OSS
            $options = $this->getUploadOptions($metas);
            $result = $this->ossClient->uploadFile(
                $this->bucket,
                $object,
                $localFilePath,
                $options
            );

            return [
                'storage'      => 'aliyun',
                'filename'     => $filename,
                'newFilename'  => $name . '.' . $ext,
                'ext'          => $ext,
                'metas'        => $metas,
                'fileSize'     => $fileSize,
                'type'         => $type,
                'filepath'     => $object,
                'hashId'       => $hashId,
                'groupCode'    => $group,
                'globalId'     => $object,
                'etag'         => $result['etag'] ?? '',
                'firstStorage' => true,
            ];
        } catch (OssException $e) {
            throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '阿里云OSS上传失败: ' . $e->getMessage());
        }
    }

    /**
     * Store base64 image to Alibaba Cloud OSS
     *
     * @param string $base64Str
     * @param string $path
     * @param string|null $name
     * @param array $options
     * @return array
     */
    public function storeBase64ImageFile($base64Str, $path, $name = null, array $options = [])
    {
        if (!preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64Str, $result)) {
            throw AttachmentException::BASE64_IMAGE_INVALID();
        }

        $ext = $result[2];
        if (!in_array($ext, $this->config['allow_image_exts'] ?? [])) {
            throw AttachmentException::IMAGE_FILE_EXT_INVALID();
        }

        $group = $options['group'] ?? 'default';
        $algo = $options['hash_algo'] ?? 'sha256';
        empty($name) && $name = time();

        $content = base64_decode(str_replace($result[1], '', $base64Str));
        $fileSize = strlen($content);

        if ($this->config['allow_image_upload_size'] * 1024 < $fileSize) {
            throw new AttachmentException(AttachmentException::IMAGE_FILE_SIZE_INVALID,
                "图片大小不能超过" . sprintf("%.2f", $this->config['allow_image_upload_size'] / 1024) . "M");
        }

        $object = $this->generateObjectKey($group, $path, $name, $ext);
        $hashId = hash($algo, $content);

        try {
            $options = $this->getUploadOptions('image/' . $ext);
            $ossResult = $this->ossClient->putObject(
                $this->bucket,
                $object,
                $content,
                $options
            );

            return [
                'storage'     => 'aliyun',
                'filename'    => $name . '.' . $ext,
                'newFilename' => $name . '.' . $ext,
                'ext'         => $ext,
                'metas'       => 'image/' . $ext,
                'fileSize'    => $fileSize,
                'type'        => 'image',
                'filepath'    => $object,
                'hashId'      => $hashId,
                'groupCode'   => $group,
                'globalId'    => $object,
                'etag'        => $ossResult['etag'] ?? '',
            ];
        } catch (OssException $e) {
            throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '阿里云OSS上传失败: ' . $e->getMessage());
        }
    }

    /**
     * Store remote file to Alibaba Cloud OSS
     *
     * @param string $url
     * @param string $path
     * @param string|null $name
     * @param array $options
     * @return array
     */
    public function storeRemoteFile($url, $path, $name = null, array $options = [])
    {
        $url = str_replace("&amp;", "&", $url);

        if (strpos($url, "http") !== 0) {
            throw AttachmentException::REMOTE_FILE_LINK_INVALID();
        }

        $heads = get_headers($url, 1);
        if (!(stristr($heads[0], "200") && stristr($heads[0], "OK"))) {
            throw AttachmentException::REMOTE_FILE_LINK_DEAD();
        }

        try {
            ob_start();
            $context = stream_context_create([
                'http' => ['follow_location' => false],
            ]);
            readfile($url, false, $context);
            $content = ob_get_contents();
            ob_end_clean();

            $urlPath = parse_url($url, PHP_URL_PATH);
            $pathInfo = explode('.', $urlPath);

            $ext = $this->getFileExtensionFromContentType($heads['Content-Type']);
            if (empty($ext)) {
                $ext = end($pathInfo);
            }

            $fileSize = strlen($content);
            $type = $this->getAttachmentTypeByExt($ext);

            if (\attachment_validate_upload_file($this->config, $ext, $fileSize)) {
                $group = $options['group'] ?? 'default';
                $algo = $options['hash_algo'] ?? 'sha256';
                empty($name) && $name = time();

                $object = $this->generateObjectKey($group, $path, $name, $ext);
                $hashId = hash($algo, $content);

                $uploadOptions = $this->getUploadOptions($heads['Content-Type'] ?? 'application/octet-stream');
                $ossResult = $this->ossClient->putObject(
                    $this->bucket,
                    $object,
                    $content,
                    $uploadOptions
                );

                return [
                    'storage'     => 'aliyun',
                    'filename'    => $name . '.' . $ext,
                    'newFilename' => $name . '.' . $ext,
                    'ext'         => $ext,
                    'metas'       => $heads['Content-Type'] ?? 'application/octet-stream',
                    'fileSize'    => $fileSize,
                    'type'        => $type,
                    'filepath'    => $object,
                    'hashId'      => $hashId,
                    'groupCode'   => $group,
                    'globalId'    => $object,
                    'etag'        => $ossResult['etag'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Delete file from Alibaba Cloud OSS
     *
     * @param array|null $file Attachment record
     * @return bool
     */
    public function deleteFile($file)
    {
        if (empty($file) || empty($file['filepath'])) {
            return false;
        }

        $object = ltrim($file['filepath'], 'uploads/');

        try {
            $this->ossClient->deleteObject($this->bucket, $object);
            return true;
        } catch (OssException $e) {
            error_log('Aliyun OSS delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate object key for OSS storage
     *
     * @param string $group
     * @param string $path
     * @param string $name
     * @param string $ext
     * @return string
     */
    protected function generateObjectKey($group, $path, $name, $ext)
    {
        $basePath = $group . '/' . (!empty($path) ? $path . '/' : '');
        return $basePath . $name . '.' . $ext;
    }

    /**
     * Get file access URL
     *
     * @param string $object Object key
     * @return string
     */
    protected function getFileUrl($object)
    {
        $protocol = $this->useSsl ? 'https' : 'http';

        // Use CDN domain if enabled
        if ($this->useCdn && !empty($this->cdnDomain)) {
            $domain = preg_replace('/^https?:\/\//', '', $this->cdnDomain);
            return $protocol . '://' . $domain . '/' . $object;
        }

        // Use custom domain if set
        if ($this->isCName && !empty($this->customDomain)) {
            $domain = preg_replace('/^https?:\/\//', '', $this->customDomain);
            return $protocol . '://' . $domain . '/' . $object;
        }

        // Use default bucket endpoint
        $domain = preg_replace('/^https?:\/\//', '', $this->endpoint);
        return $protocol . '://' . $this->bucket . '.' . $domain . '/' . $object;
    }

    /**
     * Get upload options for OSS
     *
     * @param string $contentType MIME type
     * @return array
     */
    protected function getUploadOptions($contentType = null)
    {
        $options = [
            OssClient::OSS_CHECK_MD5 => false,
            OssClient::OSS_PART_SIZE => 5 * 1024 * 1024,
        ];

        if (!empty($contentType)) {
            $options[OssClient::OSS_HEADERS] = [
                'Content-Type' => $contentType,
            ];
        }

        return $options;
    }
}
