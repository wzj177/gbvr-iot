<?php

namespace CoreW\Business\RecordMerge\Exception;

use CoreW\Exception\AbstractBizException;

class RecordMergeException extends AbstractBizException
{
    const EXCEPTION_MODULE = 32;

    // 404
    const MERGE_TASK_NOT_FOUND = 4043201;
    const CHANNEL_NOT_FOUND = 4043202;

    // 400
    const INVALID_TIME_RANGE = 4003203;
    const NO_FILES_IN_RANGE = 4003204;
    const MERGE_ALREADY_EXISTS = 4003205;
    const CANNOT_CANCEL = 4003206;

    // 500
    const MERGE_FAILED = 5003206;

    public function __construct($code, ?string $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    public function setMessages()
    {
        $this->messages = [
            self::MERGE_TASK_NOT_FOUND => '合并任务不存在',
            self::CHANNEL_NOT_FOUND    => '通道不存在',
            self::INVALID_TIME_RANGE   => '时间范围无效',
            self::NO_FILES_IN_RANGE    => '该时间范围内没有录像文件',
            self::MERGE_ALREADY_EXISTS => '该时间范围已存在合并任务',
            self::CANNOT_CANCEL        => '当前状态不允许取消',
            self::MERGE_FAILED         => '录像合并失败',
        ];
    }
}
