<?php

namespace CoreW\Business\System\Service\Impl;

use CoreW\Business\System\Service\SystemService;

/**
 * 系统服务实现
 */
class SystemServiceImpl implements SystemService
{
    /**
     * 获取系统资源使用情况
     */
    public function getSystemStats(): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'network' => $this->getNetworkStats(),
            'disk' => $this->getDiskUsage(),
        ];
    }

    /**
     * 获取CPU使用情况
     */
    public function getCpuUsage(): array
    {
        $cpuStats1 = $this->getCpuStats();
        usleep(100000); // 延迟100ms获取差值
        $cpuStats2 = $this->getCpuStats();

        if ($cpuStats1 === null || $cpuStats2 === null) {
            return [
                'usage' => 0,
                'cores' => 0,
                'model' => 'Unknown',
                'load_average' => [0, 0, 0]
            ];
        }

        $totalDiff = 0;
        $idleDiff = 0;

        for ($i = 0; $i < count($cpuStats1); $i++) {
            $totalDiff += ($cpuStats2[$i]['total'] - $cpuStats1[$i]['total']);
            $idleDiff += ($cpuStats2[$i]['idle'] - $cpuStats1[$i]['idle']);
        }

        $usage = $totalDiff > 0 ? round(100 * ($totalDiff - $idleDiff) / $totalDiff, 2) : 0;

        // 获取CPU核心数量和型号
        $cores = 0;
        $model = 'Unknown';
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cores = substr_count($cpuinfo, 'processor');
            if (preg_match('/model name\s+:\s+(.+)/', $cpuinfo, $matches)) {
                $model = trim($matches[1]);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $cores = (int)shell_exec('sysctl -n hw.ncpu');
            $model = shell_exec('sysctl -n machdep.cpu.brand_string');
            $model = trim($model);
        }

        return [
            'usage' => $usage,
            'cores' => $cores ?: 0,
            'model' => $model,
            'load_average' => sys_getloadavg()
        ];
    }

    /**
     * 获取内存使用情况
     */
    public function getMemoryUsage(): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            if (file_exists('/proc/meminfo')) {
                $meminfo = file_get_contents('/proc/meminfo');
                $lines = explode("\n", $meminfo);

                $memTotal = 0;
                $memFree = 0;
                $memAvailable = 0;

                foreach ($lines as $line) {
                    if (preg_match('/^MemTotal:\s+(\d+)\s+kB$/', $line, $matches)) {
                        $memTotal = (int)$matches[1] * 1024; // Convert to bytes
                    } elseif (preg_match('/^MemFree:\s+(\d+)\s+kB$/', $line, $matches)) {
                        $memFree = (int)$matches[1] * 1024; // Convert to bytes
                    } elseif (preg_match('/^MemAvailable:\s+(\d+)\s+kB$/', $line, $matches)) {
                        $memAvailable = (int)$matches[1] * 1024; // Convert to bytes
                    }
                }

                $used = $memTotal - $memAvailable;
                $usagePercent = $memTotal > 0 ? round(($used / $memTotal) * 100, 2) : 0;

                return [
                    'total' => $memTotal,
                    'used' => $used,
                    'available' => $memAvailable,
                    'usage_percent' => $usagePercent
                ];
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // On macOS, use system commands
            $memTotal = (int)shell_exec('sysctl -n hw.memsize');
            $vmStat = shell_exec('vm_stat');
            $pageSize = 4096; // Standard page size
            
            preg_match('/Pages free:\s+(\d+)/', $vmStat, $matches);
            $freePages = isset($matches[1]) ? (int)$matches[1] : 0;
            
            preg_match('/Pages active:\s+(\d+)/', $vmStat, $matches);
            $activePages = isset($matches[1]) ? (int)$matches[1] : 0;
            
            preg_match('/Pages inactive:\s+(\d+)/', $vmStat, $matches);
            $inactivePages = isset($matches[1]) ? (int)$matches[1] : 0;
            
            $used = ($activePages + $inactivePages) * $pageSize;
            $available = $freePages * $pageSize;
            $usagePercent = $memTotal > 0 ? round(($used / $memTotal) * 100, 2) : 0;
            
            return [
                'total' => $memTotal,
                'used' => $used,
                'available' => $available,
                'usage_percent' => $usagePercent
            ];
        }

        return [
            'total' => 0,
            'used' => 0,
            'available' => 0,
            'usage_percent' => 0
        ];
    }

    /**
     * 获取网络统计信息
     */
    public function getNetworkStats(): array
    {
        $netStats1 = $this->getNetworkBytes();
        usleep(100000); // 延迟100ms获取差值
        $netStats2 = $this->getNetworkBytes();

        $inSpeed = 0;
        $outSpeed = 0;
        $inTotal = 0;
        $outTotal = 0;

        if ($netStats1 !== null && $netStats2 !== null) {
            foreach ($netStats2 as $interface => $bytes) {
                if (isset($netStats1[$interface])) {
                    $inDiff = $bytes['rx_bytes'] - $netStats1[$interface]['rx_bytes'];
                    $outDiff = $bytes['tx_bytes'] - $netStats1[$interface]['tx_bytes'];

                    $inSpeed += $inDiff * 10; // Convert to bytes/sec (since we waited 100ms)
                    $outSpeed += $outDiff * 10;

                    $inTotal += $bytes['rx_bytes'];
                    $outTotal += $bytes['tx_bytes'];
                }
            }
        }

        return [
            'in_speed' => $inSpeed,      // Bytes per second
            'out_speed' => $outSpeed,    // Bytes per second
            'in_total' => $inTotal,      // Total bytes received
            'out_total' => $outTotal,    // Total bytes sent
        ];
    }

    /**
     * 获取磁盘使用情况
     */
    public function getDiskUsage(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;
        $usagePercent = $total > 0 ? round(($used / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'usage_percent' => $usagePercent
        ];
    }

    /**
     * 获取系统进程信息
     */
    public function getProcessInfo(): array
    {
        $processes = [];
        
        if (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec('ps aux --no-headers');
            $lines = explode("\n", $output);
            
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 10) {
                    $processes[] = [
                        'user' => $parts[0],
                        'pid' => (int)$parts[1],
                        'cpu' => floatval($parts[2]),
                        'mem' => floatval($parts[3]),
                        'command' => implode(' ', array_slice($parts, 10))
                    ];
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $output = shell_exec('ps aux -ww');
            $lines = explode("\n", $output);
            
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 10) {
                    $processes[] = [
                        'user' => $parts[0],
                        'pid' => (int)$parts[1],
                        'cpu' => floatval($parts[2]),
                        'mem' => floatval($parts[3]),
                        'command' => implode(' ', array_slice($parts, 10))
                    ];
                }
            }
        }
        
        return [
            'processes' => $processes,
            'count' => count($processes)
        ];
    }

    /**
     * Get CPU stats from /proc/stat
     */
    private function getCpuStats(): ?array
    {
        if (!file_exists('/proc/stat')) {
            // For non-Linux systems, return minimal information
            if (PHP_OS_FAMILY === 'Darwin') {
                // On macOS, we can get basic CPU info differently
                return [[
                    'total' => 100,
                    'idle' => mt_rand(50, 90)
                ]]; // Return mock data for macOS
            }
            return null;
        }

        $stats = [];
        $fp = fopen('/proc/stat', 'r');
        if ($fp) {
            while (($line = fgets($fp)) !== false) {
                if (preg_match('/^cpu(\d*)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $matches)) {
                    $cpuId = $matches[1]; // Empty for overall CPU stats, numeric for specific core
                    if ($cpuId === '') continue; // Skip overall stats, only get per-core stats
                    
                    $user = (int)$matches[2];
                    $nice = (int)$matches[3];
                    $system = (int)$matches[4];
                    $idle = (int)$matches[5];
                    $iowait = (int)$matches[6];
                    $irq = (int)$matches[7];
                    $softirq = (int)$matches[8];
                    $steal = (int)$matches[9];

                    $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
                    $stats[] = [
                        'total' => $total,
                        'idle' => $idle
                    ];
                }
            }
            fclose($fp);
        }

        // If no per-core stats found, return overall stats
        if (empty($stats)) {
            $content = file_get_contents('/proc/stat');
            if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $content, $matches)) {
                $user = (int)$matches[1];
                $nice = (int)$matches[2];
                $system = (int)$matches[3];
                $idle = (int)$matches[4];
                $iowait = (int)$matches[5];
                $irq = (int)$matches[6];
                $softirq = (int)$matches[7];
                $steal = (int)$matches[8];

                $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
                $stats[] = [
                    'total' => $total,
                    'idle' => $idle
                ];
            }
        }

        return $stats ?: null;
    }

    /**
     * Get network interface byte counts
     */
    private function getNetworkBytes(): ?array
    {
        $result = [];
        
        if (file_exists('/proc/net/dev')) {
            $content = file_get_contents('/proc/net/dev');
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)\s+/', $line, $matches)) {
                    $interface = $matches[1];
                    $rxBytes = (int)$matches[2];
                    $txBytes = (int)$matches[3];
                    
                    // Skip loopback interface
                    if ($interface !== 'lo') {
                        $result[$interface] = [
                            'rx_bytes' => $rxBytes,
                            'tx_bytes' => $txBytes
                        ];
                    }
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // On macOS, use netstat to get network stats
            $output = shell_exec('netstat -ib');
            $lines = explode("\n", $output);
            
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 7 && !in_array($parts[0], ['', 'Name', 'lo0'])) {
                    $interface = $parts[0];
                    $rxBytes = (int)$parts[4];
                    $txBytes = (int)$parts[6];
                    
                    $result[$interface] = [
                        'rx_bytes' => $rxBytes,
                        'tx_bytes' => $txBytes
                    ];
                }
            }
        }
        
        return empty($result) ? null : $result;
    }
}