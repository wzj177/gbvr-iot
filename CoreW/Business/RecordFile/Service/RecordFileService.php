<?php

namespace CoreW\Business\RecordFile\Service;

interface RecordFileService
{
    /**
     * 从 ZLM hook 创建录像文件记录
     *
     * @param array $hookData ZLM on_record_mp4 hook 数据
     * @param string $mediaServerId 媒体服务器 ID
     * @return array|null 创建的记录，失败返回 null
     */
    public function createFromHook(array $hookData, string $mediaServerId): ?array;

    /**
     * 查询录像文件
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchRecordFiles(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array;

    /**
     * 统计录像文件数量
     *
     * @param array $conditions 查询条件
     * @return int
     */
    public function countRecordFiles(array $conditions): int;
}
