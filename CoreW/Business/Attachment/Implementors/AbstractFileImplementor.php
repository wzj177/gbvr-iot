<?php


namespace CoreW\Business\Attachment\Implementors;


use CoreW\Bfw;
use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Core;
use CoreW\Traits\ImagineTrait;
use Webman\Http\UploadFile;

abstract class AbstractFileImplementor
{
    use ImagineTrait;

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function getAttachmentTypeByExt($ext)
    {
        $ext = strtolower($ext);
        if (in_array($ext, ['mp4', 'mpeg', 'm3u8', 'flv', 'ogv', 'webm', '3gp', '3g2'])) {
            return 'video';
        }

        if (in_array($ext, ['mp3', '3gp', '3g2', 'weba', 'wav', 'oga', 'mid', 'midi', 'aac'])) {
            return 'audio';
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'ico', 'svg', 'tif', 'tiff', 'webp'])) {
            return 'image';
        }

        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'csv'])) {
            return 'document';
        }

        return 'other';
    }

    public function getAttachmentTypeByUploadFile(UploadFile $file)
    {
        return $this->getAttachmentTypeByMimeTypeAndExt($file->getUploadMimeType(), $file->getUploadExtension());
    }

    public function getAttachmentTypeByMimeTypeAndExt($mimeType, $ext)
    {
        if (strpos($mimeType, 'image/') === 0 || 'image' === $this->getAttachmentTypeByExt($ext)) {
            return 'image';
        }

        if (strpos($mimeType, 'audio/') === 0) {
            return 'audio';
        }

        if (strpos($mimeType, 'video/') === 0) {
            return 'video';
        }

        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'csv'])) {
            return 'document';
        }

        return 'other';
    }

    /**
     *
     * 根据 header content-type 枚举 文件后缀
     *
     * @param $contentType
     * @return string|null
     */
    protected function getFileExtensionFromContentType($contentType)
    {
        switch ($contentType) {
            case 'image/jpeg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'image/gif':
                return 'gif';
            case 'image/bmp':
                return 'bmp';
            case 'image/x-icon':
                return 'ico';
            case 'image/svg+xml':
                return 'svg';
            case 'image/webp':
                return 'webp';
            case 'video/mp4':
                return 'mp4';
            case 'video/x-msvideo':
                return 'avi';
            case 'video/quicktime':
                return 'mov';
            case 'video/x-matroska':
                return 'mkv';
            case 'audio/mpeg':
                return 'mp3';
            case 'audio/ogg':
                return 'ogg';
            case 'audio/wav':
                return 'wav';
            case 'application/pdf':
                return 'pdf';
            case 'application/vnd.ms-powerpoint':
                return 'ppt';
            case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
                return 'pptx';
            case 'application/msword':
                return 'doc';
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return 'docx';
            case 'application/vnd.ms-excel':
                return 'xls';
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                return 'xlsx';
            case 'application/zip':
                return 'zip';
            case 'application/x-rar-compressed':
                return 'rar';
            case 'application/x-7z-compressed':
                return '7z';
            case 'application/vnd.android.package-archive':
                return 'apk';
            case 'application/x-msdownload':
                return 'exe';
            // 添加更多的类型和对应的文件后缀
            default:
                return null;
        }
    }

    protected function getDefaultSubPath()
    {
        return date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d');
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->getBiz()->service('Attachment:AttachmentService');
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }
}