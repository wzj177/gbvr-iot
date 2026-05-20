<?php

namespace CoreW\Dao\Annotation;

/**
 * @Annotation
 * @Target("METHOD")
 */
class RowCache
{
    /**
     * 定义哪些字段更新时，需要清除当前Cache条目
     */
    public $relFields = [];

    public $primaryQuery = false;

    public function __construct(array $data)
    {
        if (!empty($data['value'])) {
            $this->relFields = $data['value'];
        }

        if (!empty($data['primary'])) {
            $this->primaryQuery = true;
        }
    }
}
