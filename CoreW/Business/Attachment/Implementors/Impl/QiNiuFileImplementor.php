<?php


namespace CoreW\Business\Attachment\Implementors\Impl;


use CoreW\Business\Attachment\Exception\AttachmentException;
use CoreW\Business\Attachment\Implementors\AbstractFileImplementor;
use CoreW\Business\Attachment\Implementors\FileImplementor;
use Qiniu\Auth;
use Qiniu\Config;
use Qiniu\Http\Error;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use Webman\Http\UploadFile;

class QiNiuFileImplementor extends AbstractFileImplementor implements FileImplementor
{
    /**
     * @var Auth Qiniu Auth instance
     */
    protected $auth;

    /**
     * @var UploadManager Qiniu Upload Manager
     */
    protected $uploadMgr;

    /**
     * @var BucketManager Qiniu Bucket Manager
     */
    protected $bucketMgr;

    /**
     * @var string Bucket name
     */
    protected $bucket;

    /**
     * @var string Bucket domain
     */
    protected $domain;

    /**
     * @var string Upload protocol (http/https)
     */
    protected $protocol;

    /**
     * @var bool Use CDN domain
     */
    protected $useCdn;

    /**
     * @var string CDN domain
     */
    protected $cdnDomain;

    /**
     * Initialize Qiniu client with configuration
     */
    public function __construct($config)
    {
        parent::__construct($config);

        $this->bucket = $config['bucket'] ?? '';
        $this->domain = $config['domain'] ?? '';
        $this->protocol = $config['protocol'] ?? 'https';
        $this->useCdn = $config['use_cdn'] ?? false;
        $this->cdnDomain = $config['cdn_domain'] ?? '';

        if (empty($config['access_key']) || empty($config['secret_key']) || empty($this->bucket)) {
            throw AttachmentException::OSS_CONFIG_ERROR('Qiniu配置不完整，请检查access_key、secret_key和bucket');
        }

        $this->auth = new Auth($config['access_key'], $config['secret_key']);

        // Set zone if specified
        $zoneConfig = null;
        if (!empty($config['zone'])) {
            $zoneConfig = new Config();
            // Zone configuration will be handled by the SDK automatically
        }

        $this->uploadMgr = new UploadManager($zoneConfig);
        $this->bucketMgr = new BucketManager($this->auth, $zoneConfig);
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
     * Store uploaded file to Qiniu
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

        // Generate file key
        $key = $this->generateFileKey($group, $path, $name, $ext);

        // Calculate hash for deduplication
        $localFilePath = $file->getRealPath();
        $hashId = hash_file($algo, $localFilePath);

        // Check if file already exists by hash
        $existFile = $this->getFileByHashId($hashId);
        if (!empty($existFile)) {
            return [
                'storage'      => 'qiniu',
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

        // Upload to Qiniu
        $token = $this->auth->uploadToken($this->bucket);
        [$result, $error] = $this->uploadMgr->putFile($token, $key, $localFilePath);

        if ($error !== null) {
            throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '七牛云上传失败: ' . $error->message());
        }

        return [
            'storage'      => 'qiniu',
            'filename'     => $filename,
            'newFilename'  => $name . '.' . $ext,
            'ext'          => $ext,
            'metas'        => $metas,
            'fileSize'     => $fileSize,
            'type'         => $type,
            'filepath'     => $key,
            'hashId'       => $hashId,
            'groupCode'    => $group,
            'globalId'     => $result['key'] ?? $key,
            'etag'         => $result['hash'] ?? '',
            'firstStorage' => true,
        ];
    }

    /**
     * Store base64 image to Qiniu
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

        $key = $this->generateFileKey($group, $path, $name, $ext);
        $hashId = hash($algo, $content);

        // Upload to Qiniu
        $token = $this->auth->uploadToken($this->bucket);
        [$result, $error] = $this->uploadMgr->put($token, $key, $content);

        if ($error !== null) {
            throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '七牛云上传失败: ' . $error->message());
        }

        return [
            'storage'     => 'qiniu',
            'filename'    => $name . '.' . $ext,
            'newFilename' => $name . '.' . $ext,
            'ext'         => $ext,
            'metas'       => 'image/' . $ext,
            'fileSize'    => $fileSize,
            'type'        => 'image',
            'filepath'    => $key,
            'hashId'      => $hashId,
            'groupCode'   => $group,
            'globalId'    => $result['key'] ?? $key,
            'etag'        => $result['hash'] ?? '',
        ];
    }

    /**
     * Store remote file to Qiniu
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

                $key = $this->generateFileKey($group, $path, $name, $ext);
                $hashId = hash($algo, $content);

                // Upload to Qiniu
                $token = $this->auth->uploadToken($this->bucket);
                [$result, $error] = $this->uploadMgr->put($token, $key, $content);

                if ($error !== null) {
                    throw new AttachmentException(AttachmentException::OSS_UPLOAD_FAILED, '七牛云上传失败: ' . $error->message());
                }

                return [
                    'storage'     => 'qiniu',
                    'filename'    => $name . '.' . $ext,
                    'newFilename' => $name . '.' . $ext,
                    'ext'         => $ext,
                    'metas'       => $heads['Content-Type'] ?? 'application/octet-stream',
                    'fileSize'    => $fileSize,
                    'type'        => $type,
                    'filepath'    => $key,
                    'hashId'      => $hashId,
                    'groupCode'   => $group,
                    'globalId'    => $result['key'] ?? $key,
                    'etag'        => $result['hash'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Delete file from Qiniu
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

        $error = $this->bucketMgr->delete($this->bucket, $key);

        if ($error !== null) {
            // Log error but don't throw exception for delete operation
            error_log('Qiniu delete failed: ' . $error->message());
            return false;
        }

        return true;
    }

    /**
     * Generate file key for Qiniu storage
     *
     * @param string $group
     * @param string $path
     * @param string $name
     * @param string $ext
     * @return string
     */
    protected function generateFileKey($group, $path, $name, $ext)
    {
        $basePath = $group . '/' . (!empty($path) ? $path . '/' : '');
        return $basePath . $name . '.' . $ext;
    }

    /**
     * Get file access URL
     *
     * @param string $key File key
     * @return string
     */
    protected function getFileUrl($key)
    {
        $domain = $this->useCdn && !empty($this->cdnDomain) ? $this->cdnDomain : $this->domain;

        if (empty($domain)) {
            return '';
        }

        $protocol = $this->protocol ? : 'https';

        // Remove protocol from domain if it exists
        $domain = preg_replace('/^https?:\/\//', '', $domain);

        return $protocol . '://' . $domain . '/' . $key;
    }
}
