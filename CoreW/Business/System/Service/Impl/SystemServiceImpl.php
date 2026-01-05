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
        $usage = 0;
        $cores = 0;
        $model = 'Unknown';

        // 获取CPU核心数量和型号
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cores = substr_count($cpuinfo, 'processor');
            if (preg_match('/model name\s*:\s*(.+)/', $cpuinfo, $matches)) {
                $model = trim($matches[1]);
            }

            // Linux: 通过两次采样计算使用率
            $cpuStats1 = $this->getCpuStats();
            usleep(100000); // 延迟100ms获取差值
            $cpuStats2 = $this->getCpuStats();

            if ($cpuStats1 !== null && $cpuStats2 !== null) {
                $totalDiff = 0;
                $idleDiff = 0;

                for ($i = 0; $i < count($cpuStats1); $i++) {
                    $totalDiff += ($cpuStats2[$i]['total'] - $cpuStats1[$i]['total']);
                    $idleDiff += ($cpuStats2[$i]['idle'] - $cpuStats1[$i]['idle']);
                }

                $usage = $totalDiff > 0 ? round(100 * ($totalDiff - $idleDiff) / $totalDiff, 2) : 0;
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS: 使用 top 命令直接获取 CPU 使用率
            $cores = (int)shell_exec('sysctl -n hw.ncpu');
            $model = shell_exec('sysctl -n machdep.cpu.brand_string');
            $model = trim($model);

            // top -l 1 返回单次采样，直接获取使用率
            $output = shell_exec('top -l 1 -n 0 2>/dev/null');
            if ($output) {
                // 尝试匹配多种可能的格式
                $matched = false;
                $patterns = [
                    '/CPU usage:\s+([\d.]+)%\s+user,\s*([\d.]+)%\s+sys,\s*([\d.]+)%\s+idle/',  // 标准格式
                    '/CPU usage:\s+([\d.]+)%\s+user,\s+([\d.]+)%\s+sys,\s+([\d.]+)%\s+idle/',  // 更多空格
                    '/([\d.]+)%\s+user,\s*([\d.]+)%\s+sys,\s*([\d.]+)%\s+idle/',             // 省略 "CPU usage:"
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $output, $matches)) {
                        $user = (float)$matches[1];
                        $sys = (float)$matches[2];
                        $idle = (float)$matches[3];
                        $usage = $user + $sys; // 总使用率 = user + sys
                        $matched = true;
                        break;
                    }
                }

                // 如果以上都失败，尝试用 iostat
                if (!$matched) {
                    $iostat = shell_exec('iostat -n 0 2>/dev/null');
                    if ($iostat && preg_match('/\s+(\d+\.\d+)\s+\d+\.\d+\s+\d+\.\d+/', $iostat, $matches)) {
                        $usage = 100 - (float)$matches[1]; // iostat 返回 idle 百分比
                    }
                }
            }
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
     * Get CPU stats from /proc/stat or macOS top command
     */
    private function getCpuStats(): ?array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            // On macOS, use top command to get CPU usage
            $output = shell_exec('top -l 1 -n 0');
            if ($output && preg_match('/CPU usage:\s+([\d.]+)%\s+user,([\d.]+)%\s+sys,([\d.]+)%\s+idle/', $output, $matches)) {
                $user = (float)$matches[1];
                $sys = (float)$matches[2];
                $idle = (float)$matches[3];
                $total = 100; // top command returns percentages, so total is 100

                return [[
                    'total' => $total,
                    'idle' => $idle
                ]];
            }
            // Fallback to mock data if top command fails
            return [[
                'total' => 100,
                'idle' => 0
            ]];
        }

        if (!file_exists('/proc/stat')) {
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
                // 格式: eth0: 1234567 0 0 0 0 0 0 7654321 0 0 0 0 0 0
                // 接收字节在位置1，发送字节在位置9
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
            // macOS: 使用 netstat -I 获取网络接口统计
            // netstat -I 输出格式更清晰
            $output = shell_exec('netstat -I -b 2>/dev/null');
            if ($output) {
                $lines = explode("\n", $output);

                foreach ($lines as $line) {
                    // 跳过空行和表头
                    if (empty(trim($line)) || preg_match('/^Name/', $line) || preg_match('/^<Link/', $line)) {
                        continue;
                    }

                    // 格式: en0   1500  <Link#5>    00:11:22:33:44:55    1234567 0    7654321 0
                    // 或: en0  1234567890 7654321098 0 0 ...
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 10 && !in_array($parts[0], ['lo0', 'lo'])) {
                        $interface = $parts[0];
                        // 在 netstat -I -b 输出中，Ibytes 通常在第6列，Obytes 在第9列
                        // 但格式可能变化，所以尝试多种方式
                        $rxBytes = 0;
                        $txBytes = 0;

                        // 尝试找到字节列
                        foreach ($parts as $index => $value) {
                            if (is_numeric($value) && $value > 1000000) {
                                if ($rxBytes === 0) {
                                    $rxBytes = (int)$value;
                                } else {
                                    $txBytes = (int)$value;
                                    break;
                                }
                            }
                        }

                        if ($rxBytes > 0 || $txBytes > 0) {
                            $result[$interface] = [
                                'rx_bytes' => $rxBytes,
                                'tx_bytes' => $txBytes
                            ];
                        }
                    }
                }
            }

            // 如果 netstat -I 失败，尝试使用 ifconfig
            if (empty($result)) {
                // 获取所有网络接口
                $interfaces = shell_exec("ifconfig -a | grep '^[a-z]' | awk '{print $1}' | tr -d ':'");
                if ($interfaces) {
                    $interfaceList = explode("\n", trim($interfaces));
                    foreach ($interfaceList as $interface) {
                        $interface = trim($interface);
                        if (in_array($interface, ['lo0', 'lo', '']) || empty($interface)) {
                            continue;
                        }

                        // 使用 netstat -I 获取特定接口的统计
                        $stat = shell_exec("netstat -I -b -I {$interface} 2>/dev/null | tail -n +2");
                        if ($stat) {
                            $parts = preg_split('/\s+/', trim($stat));
                            if (count($parts) >= 10) {
                                // 格式: Name  Mtu  Network  Address  Ipkts Ierrs Ibytes  Opkts Oerrs Obytes
                                $rxBytes = isset($parts[6]) && is_numeric($parts[6]) ? (int)$parts[6] : 0;
                                $txBytes = isset($parts[9]) && is_numeric($parts[9]) ? (int)$parts[9] : 0;

                                $result[$interface] = [
                                    'rx_bytes' => $rxBytes,
                                    'tx_bytes' => $txBytes
                                ];
                            }
                        }
                    }
                }
            }
        }

        return empty($result) ? null : $result;
    }
}