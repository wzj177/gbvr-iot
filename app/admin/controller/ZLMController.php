<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Request;
use support\Response;

class ZLMController extends BaseController
{
    public function restart(Request $request)
    {
        try {
            $zlmClient = $this->getZlmClient();
            $result = $zlmClient->restartServer();
            print_r($result);

            return $this->createSuccessJsonResponse();
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取ZLMediaKit状态
     *
     * @param Request $request
     * @return Response
     */
    public function getZLMediaKitStats(Request $request): Response
    {
        try {
            $zlmClient = $this->getZlmClient();

            // 获取版本信息
            $versionResp = $zlmClient->getVersion();
            $version = $versionResp ?? 'Unknown';

            // 获取服务器配置
            $configResp = $zlmClient->getServerConfig();

            // 获取线程负载
            $loadResp = $zlmClient->getThreadsLoad();

            // 获取媒体列表（流统计）
            $mediaListResp = $zlmClient->getMediaList();

            // 获取RTP服务器列表
            $rtpListResp = $zlmClient->listRtpServer();

            // 解析数据
            $running = !empty($configResp) && $configResp['code'] === 0;
            $buildDate = $configResp['data'][0]['build_time'] ?? '';
            $gitHash = $configResp['data'][0]['git_commit_hash'] ?? '';

            // 流统计
            $streamCount = 0;
            $recordStreamCount = 0;
            $otherStreamCount = 0;
            if ($mediaListResp && $mediaListResp['code'] === 0) {
                $streamCount = is_array($mediaListResp['data']) ? count($mediaListResp['data']) : 0;
            }
            if ($rtpListResp && is_array($rtpListResp)) {
                $recordStreamCount = count($rtpListResp);
            }
            $otherStreamCount = $streamCount - $recordStreamCount;

            // 线程统计
            $threadCount = 0;
            $cpuUsage = 0;
            $memoryUsage = 0;
            $dataBufferSize = 0;
            if ($loadResp && $loadResp['code'] === 0) {
                $threadCount = is_array($loadResp['data']) ? count($loadResp['data']) : 0;
                // 计算平均CPU和内存使用率
                $totalCpu = 0;
                $totalMemory = 0;
                $totalBuffer = 0;
                foreach (($loadResp['data'] ?? []) as $thread) {
                    $totalCpu += $thread['cpu_usage'] ?? 0;
                    $totalMemory += $thread['memory_used'] ?? 0;
                    $totalBuffer += $thread['data_buffer_size'] ?? 0;
                }
                if ($threadCount > 0) {
                    $cpuUsage = round($totalCpu / $threadCount, 2);
                    $memoryUsage = round($totalMemory / $threadCount, 2);
                    $dataBufferSize = $totalBuffer;
                }
            }

            // 会话和连接统计（从服务器配置获取）
            $sessionCount = $configResp['data'][0]['thread_num'] ?? 0;
            $tcpConnectionCount = $configResp['data'][0]['connection_num'] ?? 0;
            $udpConnectionCount = 0; // ZLM API可能不直接提供此数据
            $totalConnectionCount = $tcpConnectionCount + $udpConnectionCount;

            // 带宽统计（模拟数据，实际需要从ZLM获取）
            $totalBandwidth = 0;
            $rtspBandwidth = 0;
            $httpFlvBandwidth = 0;
            $wsFlvBandwidth = 0;
            $hlsBandwidth = 0;
            $rtmpBandwidth = 0;
            $websocketBandwidth = 0;

            // 获取运行时间（从启动时间计算）
            $uptime = 0;
            if (isset($configResp['data'][0]['start_time'])) {
                $startTime = strtotime($configResp['data'][0]['start_time']);
                $uptime = time() - $startTime;
            }

            // 编解码器支持（固定值）
            $videoCodecs = ['H264', 'H265', 'MJPEG'];
            $audioCodecs = ['AAC', 'PCMU', 'PCMA', 'G711', 'Opus'];

            return $this->createSuccessJsonResponse([
                // 服务状态
                'running' => $running,
                'version' => $version,
                'build_date' => $buildDate,
                'git_hash' => $gitHash,
                'uptime' => $uptime,

                // 流统计
                'stream_count' => $streamCount,
                'record_stream_count' => $recordStreamCount,
                'other_stream_count' => $otherStreamCount,

                // 连接统计
                'session_count' => $sessionCount,
                'tcp_connection_count' => $tcpConnectionCount,
                'udp_connection_count' => $udpConnectionCount,
                'total_connection_count' => $totalConnectionCount,

                // 带宽统计
                'total_bandwidth' => $totalBandwidth,
                'rtsp_bandwidth' => $rtspBandwidth,
                'http_flv_bandwidth' => $httpFlvBandwidth,
                'ws_flv_bandwidth' => $wsFlvBandwidth,
                'hls_bandwidth' => $hlsBandwidth,
                'rtmp_bandwidth' => $rtmpBandwidth,
                'websocket_bandwidth' => $websocketBandwidth,

                // 性能指标
                'cpu_usage' => (int)$cpuUsage,
                'memory_usage' => (int)$memoryUsage,
                'thread_count' => $threadCount,
                'data_buffer_size' => $dataBufferSize,

                // 编解码支持
                'video_codecs' => $videoCodecs,
                'audio_codecs' => $audioCodecs,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * @return ZLMClient
     */
    protected function getZlmClient(): ZLMClient
    {
        return $this->getBiz()->offsetGet('zlm_sdk');
    }
}