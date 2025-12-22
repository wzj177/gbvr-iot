<?php

namespace CoreW\Business\System\Service;

/**
 * 系统服务接口
 */
interface SystemService
{
    /**
     * 获取系统资源使用情况
     * 
     * @return array
     */
    public function getSystemStats(): array;

    /**
     * 获取CPU使用情况
     * 
     * @return array
     */
    public function getCpuUsage(): array;

    /**
     * 获取内存使用情况
     * 
     * @return array
     */
    public function getMemoryUsage(): array;

    /**
     * 获取网络统计信息
     * 
     * @return array
     */
    public function getNetworkStats(): array;

    /**
     * 获取磁盘使用情况
     * 
     * @return array
     */
    public function getDiskUsage(): array;

    /**
     * 获取系统进程信息
     * 
     * @return array
     */
    public function getProcessInfo(): array;
}