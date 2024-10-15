<?php


namespace CoreW\Business\Attachment\Dao;


use CoreW\Dao\AdvancedDaoInterface;

interface AttachmentGroupDao extends AdvancedDaoInterface
{
    public function getByCode($code);

    public function getByTitle($title);

    /**
     * 获取指定编码集合的分组
     *
     * @param array $codes
     * @return array
     */
    public function getAllByCodes(array $codes);
}