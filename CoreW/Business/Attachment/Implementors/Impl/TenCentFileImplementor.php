<?php


namespace CoreW\Business\Attachment\Implementors\Impl;


use CoreW\Business\Attachment\Exception\AttachmentException;
use CoreW\Business\Attachment\Implementors\AbstractFileImplementor;
use CoreW\Business\Attachment\Implementors\FileImplementor;
use Qcloud\Cos\Client;
use Exception;
use Webman\Http\UploadFile;

class TenCentFileImplementor extends AbstractFileImplementor implements FileImplementor
{
    /**
     * @var Client Tencent Cloud COS Client
     */
    protected $cosClient;

    /**
     * @var string Bucket name
     */
    protected $bucket;

    /**
     * @var string Bucket region
     */
    protected $region;

    /**
     * @var string App ID
     */
    protected $appId;

    /**
     * @var bool Use CDN domain
     */
    protected $useCdn;

    /**
     * @var string CDN domain
     */
    protected $cdnDomain;

    /**
     * @var bool CDN secure (HTTPS)
     */
    protected $cdnSecure;

    /**
     * Initialize Tencent Cloud COS client with configuration
     */
    public function __construct($config)
    {
        parent::__construct($config);

        $this->bucket = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? 'ap-guangzhou';
        $this->appId = $config['app_id'] ?? '';
        $this->useCdn = $config['use_cdn'] ?? false;
        $this->cdnDomain = $config['cdn_domain'] ?? '';
        $this->cdnSecure = $config['cdn_secure'] ?? true;

        $secretId = $config['secret_id'] ?? '';
        $secretKey = $config['secret_key'] ?? '';

        if (empty($secretId) || empty($secretKey) || empty($this->bucket)) {
            throw AttachmentException::OSS_CONFIG_ERROR('腾讯云COS配置不完整，请检查secret_id、secret_key和bucket');
        }

        try {
            $this->cosClient = new Client([
                'region'      => $this->region,
                'schema'      => 'https',
                'credentials' => [
                    'secretId'  => $secretId,
                    'secretKey' => $secretKey,
                ],
            ]);
        } catch (Exception $e) {
            throw AttachmentException::OSS_CONFIG_ERROR('腾讯云COS初始化失败: ' . $e->getMessage());
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

        $key = $file['filepath'] ?? '';
        if (empty($key)) {
            return null;
        }

        // Remove 'uploads/' prefix if exists
        $key = ltrim($key, 'uploads/');

        return $this->getFileUrl($key);
    }

    /**
     * Store uploaded file to Tencent Cloud COS
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
        $key = $this->generateObjectKey($group, $path, $name, $ext);

        // Calculate hash for deduplication
        $localFilePath = $file->getRealPath();
        $hashId = hash_file($algo, $localFilePath);

        // Check if file already exists by hash
        $existFile = $this->getFileByHashId($hashId);
        if (!empty($existFile)) {
            return [
                'storage'      => 'tencent',
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
            // Upload to COS
            $result = $this->cosClient->upload(
                [
                    'Bucket'      => $this->bucket,
                    'Key'         => $key,
                    'Body'        => fopen($localFilePath, 'rb'),
                    'ContentType' => $metas,
                ]
            );

            return [
                'storage'      => 'tencent',
                'filename'     => $filename,
                'newFilename'  => $name . '.' . $ext,
                'ext'          => $ext,
                'metas'        => $metas,
                'fileSize'     => $fileSize,
                'type'         => $type,
                'filepath'     => $key,
                'hashId'       => $hashId,
                'groupCode'    => $group,
                'globalId'     => $key,
                'etag'         => $result['ETag'] ?? '',
                'firstStorage' => true,
            ];
        } catch (Exception $e) {
            throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '腾讯云COS上传失败: ' . $e->getMessage());
        }
    }

    /**
     * Store base64 image to Tencent Cloud COS
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

        $key = $this->generateObjectKey($group, $path, $name, $ext);
        $hashId = hash($algo, $content);

        try {
            $cosResult = $this->cosClient->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => $key,
                'Body'        => $content,
                'ContentType' => 'image/' . $ext,
            ]);

            return [
                'storage'     => 'tencent',
                'filename'    => $name . '.' . $ext,
                'newFilename' => $name . '.' . $ext,
                'ext'         => $ext,
                'metas'       => 'image/' . $ext,
                'fileSize'    => $fileSize,
                'type'        => 'image',
                'filepath'    => $key,
                'hashId'      => $hashId,
                'groupCode'   => $group,
                'globalId'    => $key,
                'etag'        => $cosResult['ETag'] ?? '',
            ];
        } catch (Exception $e) {
            throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '腾讯云COS上传失败: ' . $e->getMessage());
        }
    }

    /**
     * Store remote file to Tencent Cloud COS
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

                $key = $this->generateObjectKey($group, $path, $name, $ext);
                $hashId = hash($algo, $content);

                $cosResult = $this->cosClient->putObject([
                    'Bucket'      => $this->bucket,
                    'Key'         => $key,
                    'Body'        => $content,
                    'ContentType' => $heads['Content-Type'] ?? 'application/octet-stream',
                ]);

                return [
                    'storage'     => 'tencent',
                    'filename'    => $name . '.' . $ext,
                    'newFilename' => $name . '.' . $ext,
                    'ext'         => $ext,
                    'metas'       => $heads['Content-Type'] ?? 'application/octet-stream',
                    'fileSize'    => $fileSize,
                    'type'        => $type,
                    'filepath'    => $key,
                    'hashId'      => $hashId,
                    'groupCode'   => $group,
                    'globalId'    => $key,
                    'etag'        => $cosResult['ETag'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Delete file from Tencent Cloud COS
     *
     * @param array|null $file Attachment record
     * @return bool
     */
    public function deleteFile($file)
    {
        if (empty($file) || empty($file['filepath'])) {
            return false;
        }

        $key = ltrim($file['filepath'], 'uploads/');

        try {
            $this->cosClient->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            return true;
        } catch (Exception $e) {
            error_log('Tencent COS delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate object key for COS storage
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
     * @param string $key Object key
     * @return string
     */
    protected function getFileUrl($key)
    {
        $protocol = $this->cdnSecure ? 'https' : 'http';

        // Use CDN domain if enabled
        if ($this->useCdn && !empty($this->cdnDomain)) {
            $domain = preg_replace('/^https?:\/\//', '', $this->cdnDomain);
            return $protocol . '://' . $domain . '/' . $key;
        }

        // Use default COS endpoint
        // Format: https://{bucket}-{appid}.cos.{region}.myqcloud.com/{key}
        return 'https://' . $this->bucket . '.cos.' . $this->region . '.myqcloud.com/' . $key;
    }
}
