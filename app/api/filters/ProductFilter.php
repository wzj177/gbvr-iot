<?php

namespace app\api\filters;

use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;
use support\utils\StringToolkit;
use support\utils\TimeMachineToolkit;

class ProductFilter extends Filter
{
    protected $publicFields
        = [
            'id',
            'code',
            'title',
            'cover',
            'catalogId',
            'recommend',
            'address',
            'remark',
            'clickCount',
            'likeCount',
            'useIntro',
            'userId',
            'type',
            'createdTime',
        ];

    protected $simpleFields
        = [
            'id',
            'code',
            'title',
            'cover',
            'catalogId',
            'catalogTitle',
            'recommend',
            'address',
            'remark',
            'clickCount',
            'likeCount',
            'useIntro',
            'anonymousShow',
            'status',
            'userId',
            'type',
            'createdTime',
            'scenes',
            'tags',
            'recommendTagIds',
            'customTags',
            'logo',
            'logoPosition',
            'brandWebsite',
            'userName',
            'password',
            'coverFull',
            'logoFull',
            'configs',
        ];

    protected $mode = self::SIMPLE_MODE;

    protected function simpleFields(&$data)
    {
        $this->transformCoverAndLogo($data);
        $this->formatScenes($data);
        $data['createdTimeText'] = TimeMachineToolkit::formatTime(intval($data['createdTime']));
        $data['statusText'] = BizEnum::getProductStatusItems($data['status']);
    }

    protected function publicFields(&$data)
    {
        $this->transformCoverAndLogo($data);
    }

    private function transformCoverAndLogo(&$data)
    {
        $data['coverFull'] = '';
        $data['logoFull'] = '';
        if (!empty($data['cover'])) {
            $data['coverFull'] = AssetHelper::getUploadUrl($data['cover']);
        }

        if (!empty($data['logo'])) {
            $data['logoFull'] = AssetHelper::getUploadUrl($data['logo']);
        }
    }

    private function formatScenes(&$data)
    {
        if (empty($data['scenes'])) {
            return;
        }

        foreach ($data['scenes'] as &$scene) {
            $scene['tileUrl'] = AssetHelper::getUploadUrl($scene['tilePath']);
            $scene['panoramaUrl'] = AssetHelper::getUploadUrl($scene['panorama']);
            $scene['thumbUrl'] = AssetHelper::getUploadUrl($scene['thumb']);
            $scene['panoramaSmallUrl'] = empty($scene['panoramaSmall']) ? $scene['panoramaUrl'] : AssetHelper::getUploadUrl($scene['panoramaSmall']);
        }
    }
}