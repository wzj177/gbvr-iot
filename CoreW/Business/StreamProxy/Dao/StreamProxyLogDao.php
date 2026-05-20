<?php

namespace CoreW\Business\StreamProxy\Dao;

use CoreW\Dao\AdvancedDaoInterface;

/**
 * 流代理日志 DAO 接口
 */
interface StreamProxyLogDao extends AdvancedDaoInterface
{
    /**
     * 根据流代理ID查询日志
     *
     * @param string $proxyId
     * @param array $orderBys
     * @param int $start
     * @param int $limit
     * @return array
     */
    public function findByProxyId(string $proxyId, array $orderBys = [], int $start = 0, int $limit = 100) : array;

    /**
     * 统计流代理日志数量
     *
     * @param string $proxyId
     * @return int
     */
    public function countByProxyId(string $proxyId) : int;

    /**
     * 删除指定日期之前的日志
     *
     * @param string $date 日期 (Y-m-d H:i:s)
     * @return int 删除的行数
     */
    public function deleteBeforeDate(string $date) : int;

    /**
     * 删除指定流代理的所有日志
     *
     * @param string $proxyId
     * @return int
     */
    public function deleteByProxyId(string $proxyId) : int;
}
