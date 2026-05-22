<?php


namespace app\admin\filters;


use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;

class AttachmentGroupFilter extends Filter
{
    protected $publicFields
        = [
            'id',
            'code',
            'title',
            'isDefault',
            'parentId',
            'level',
            'sort',
            'createdTime',
            'updatedTime',
        ];

    /**
     * @return array
     */
    public function publicFields(&$data) : void
    {
        $data['isDefault'] = (int)$data['isDefault'];
        $data['isDefaultText'] = $data['isDefault'] === 1 ? '系统' : '';
        $separator = "　　";
        $deep = $data['level'];
        $data['tree_title'] = $deep == 1 ? str_repeat($separator, $deep - 1) . $data['title'] : str_repeat($separator, $deep - 1) . '├' . $data['title'];
    }
}