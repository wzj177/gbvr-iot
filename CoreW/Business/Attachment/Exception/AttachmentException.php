<?php

namespace CoreW\Business\Attachment\Exception;

use CoreW\Exception\AbstractBizException;

class AttachmentException extends AbstractBizException 
{
    const ATTACHMENT_GROUP_DENY_DELETE = 4032101;
    const ATTACHMENT_GROUP_HAS_CHILD_DENY_DELETE = 4032102;
    const ATTACHMENT_DEFAULT_GROUP_DENY_UPDATE = 4032103;
    const BAD_REQUEST_BATCH_DELETE_IDS = 4002101;

    const NOTFOUND_FILE = 4042301;

    const ERROR_STATUS = 4002303;

    const PERMISSION_DENIED = 4032304;

    const IMPLEMENTOR_NOT_ALLOWED = 5002305;

    const UPLOAD_FAILED = 4002306;

    const UPLOAD_TRANS_FAILED = 5002307;

    const EXTENSION_NOT_ALLOWED = 4132309;

    const NOTFOUND_ATTACHMENT = 4042310;

    const ARGUMENTS_INVALID = 4002311;

    const IMAGE_FILE_SIZE_INVALID = 4002312;
    const AUDIO_FILE_SIZE_INVALID = 4002313;
    const VIDEO_FILE_SIZE_INVALID = 4002314;
    const OTHER_FILE_SIZE_INVALID = 4002315;
    const IMAGE_FILE_EXT_INVALID = 4002316;
    const REMOTE_FILE_LINK_INVALID = 4002317;
    const REMOTE_FILE_LINK_DEAD = 4002318;
    const BASE64_IMAGE_INVALID = 4002319;
    const ALL_FILE_EXT_INVALID = 4002320;
    const MULTI_FILES_EMPTY = 4002321;
    const FILE_RESOURCE_EMPTY = 4002322;
    const MOVE_GROUP_PARAM_ERROR = 4002323;
    const DELETE_FILE_PARAM_ERROR = 4132301;
    const SNIPPET_UPLOAD_FILE_FAILED = 5002301;
    const SNIPPET_UPLOAD_FILE_CHUNK_NUM_FAILED = 5002302;
    const SNIPPET_UPLOAD_FILE_CHUNK_MERGE_FAILED = 5002303;

    const IMAGE_MAKE_THUMB_BOX_PARAM_ERROR = 4002324;

    public function __construct($code, $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    public function setMessages()
    {
        $this->messages = [
            self::ATTACHMENT_GROUP_DENY_DELETE => '系统默认分组无法删除',
            self::ATTACHMENT_GROUP_HAS_CHILD_DENY_DELETE => '无法删除，分组已绑定到附件',
            self::ATTACHMENT_DEFAULT_GROUP_DENY_UPDATE => '系统默认分组无法编辑',
            self::BAD_REQUEST_BATCH_DELETE_IDS => '删除失败，缺少必要参数',
            self::NOTFOUND_FILE => '文件不存在',
            self::ERROR_STATUS => '状态不正确',
            self::PERMISSION_DENIED => '您无权访问此文件！',
            self::IMPLEMENTOR_NOT_ALLOWED => '不合法的实现类',
            self::UPLOAD_FAILED => '文件上传失败',
            self::EXTENSION_NOT_ALLOWED => '文件上传失败，该文件格式不允许上传',
            self::NOTFOUND_ATTACHMENT => '附件不存在',
            self::ARGUMENTS_INVALID => '参数不合法',
            self::IMAGE_FILE_EXT_INVALID => '文件上传失败，该图片格式不允许上传',
            self::REMOTE_FILE_LINK_INVALID => '文件上传失败，链接不是http链接',
            self::REMOTE_FILE_LINK_DEAD => '文件上传失败，链接不可用',
            self::UPLOAD_TRANS_FAILED => '文件上传传输错误',
            self::BASE64_IMAGE_INVALID => '文件上传失败，base64 码错误',
            self::ALL_FILE_EXT_INVALID => '文件上传失败，系统不支持该格式文件',
            self::MULTI_FILES_EMPTY => '上传文件列表为空',
            self::FILE_RESOURCE_EMPTY=> '文件上传失败，未选择文件资源',
            self::SNIPPET_UPLOAD_FILE_FAILED => '文件上传失败，分片出错',
            self::SNIPPET_UPLOAD_FILE_CHUNK_NUM_FAILED => '文件上传失败，切片数量不符',
            self::SNIPPET_UPLOAD_FILE_CHUNK_MERGE_FAILED => '文件上传失败，合并分片文件失败',
            self::MOVE_GROUP_PARAM_ERROR => '移动分组失败，参数错误',
            self::DELETE_FILE_PARAM_ERROR => '文件删除失败，参数错误',
            self::IMAGE_MAKE_THUMB_BOX_PARAM_ERROR => '图片生成缩略图失败，缺少宽高必要参数'
        ];
    }
}
