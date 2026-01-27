<?php

namespace CoreW\Business\RecordFile\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordFileDao extends AdvancedDaoInterface
{
    /**
     * 根据 stream_id 和 media_server_id 查找录像任务
     */
    public function getByStreamAndMediaServer(string $streamId, string $mediaServerId): ?array;

    /**
     * 创建录像文件记录
     */
    public function createRecordFile(array $data): array;
}
