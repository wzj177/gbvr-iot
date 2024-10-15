<?php

namespace CoreW\Business\Attachment\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface AttachmentDao extends AdvancedDaoInterface
{
    public function getOneByStorageAndPath(string $storage, string $path);

    public function batchChangeGroupCode($ids, $groupCode);

    public function getAllByIds($ids);

    public function getByHashId(string $hashId);
}
