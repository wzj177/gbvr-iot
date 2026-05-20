<?php


namespace app\admin\filters;


use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class AttachmentFilter extends Filter
{
    protected $publicFields
        = [
            'id',
            'hashId',
            'groupCode',
            'groupTitle',
            'status',
            'filename',
            'filepath',
            'coverFull',
            'url',
            'ext',
            'fileSize',
            'length',
            'type',
            'storage',
            'createClient',
            'imgSize',
            'createdTime',
        ];

    public function publicFields(&$data) : void
    {
        $data['storageText'] = BizEnum::getStorageTypeItems($data['storage']);
        $data['createClientText'] = BizEnum::getUploadClientTypeItems($data['createClient']);
        $data['typeText'] = BizEnum::getFileTypeItems($data['type']);
        $data['fileSizeText'] = \format_bytes($data['fileSize']);
        $data['lengthText'] = \format_duration($data['length']);
        $data['imgSize'] = !empty($data['imgSize']) ? json_decode($data['imgSize'], true) : null;
    }
}