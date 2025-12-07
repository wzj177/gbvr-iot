<?php

namespace app\command;

use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class GB28181Test extends Command
{
    protected static $defaultName = 'gb:test';
    protected static $defaultDescription = 'GB28181 Interactive Testing Tool';

    private Gb28181Client $sipClient;
    private ZLMClient $zlmClient;
    private array $sessionData = [];  // 保存会话数据

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('交互式 GB28181 测试工具，支持目录查询、实时视频、录像回放、PTZ控制等功能');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sipClient = new Gb28181Client();
        $this->zlmClient = new ZLMClient(config('zlm'));

        $output->writeln('');
        $output->writeln('═══════════════════════════════════════════════════════════');
        $output->writeln('         GB28181 Interactive Testing Tool');
        $output->writeln('═══════════════════════════════════════════════════════════');
        $output->writeln('');

        $helper = $this->getHelper('question');

        while (true) {
            $output->writeln('');
            $output->writeln('<fg=cyan>请选择操作：</>');
            $output->writeln('');
            
            $menuQuestion = new ChoiceQuestion(
                '请输入选项编号',
                [
                    '1'  => '1. 查询设备目录 (Catalog)',
                    '2'  => '2. 查询设备信息 (DeviceInfo)',
                    '3'  => '3. 查询设备状态 (DeviceStatus)',
                    '4'  => '4. 查询录像文件 (RecordInfo)',
                    '5'  => '5. 开始实时视频 (Live)',
                    '6'  => '6. 停止实时视频',
                    '7'  => '7. 开始录像回放 (Playback)',
                    '8'  => '8. 停止录像回放',
                    '9'  => '9. PTZ 云台控制',
                    '10' => '10. 查看会话信息',
                    '0'  => '0. 退出'
                ],
                '1'
            );
            $menuQuestion->setErrorMessage('选项 %s 无效');

            $choice = $helper->ask($input, $output, $menuQuestion);

            $output->writeln('');
            $output->writeln("<info>您选择了: {$choice}</info>");
            $output->writeln('');

            try {
                switch ($choice) {
                    case '1. 查询设备目录 (Catalog)':
                        $this->handleQueryCatalog($input, $output, $helper);
                        break;
                    
                    case '2. 查询设备信息 (DeviceInfo)':
                        $this->handleQueryDeviceInfo($input, $output, $helper);
                        break;
                    
                    case '3. 查询设备状态 (DeviceStatus)':
                        $this->handleQueryDeviceStatus($input, $output, $helper);
                        break;
                    
                    case '4. 查询录像文件 (RecordInfo)':
                        $this->handleQueryRecord($input, $output, $helper);
                        break;
                    
                    case '5. 开始实时视频 (Live)':
                        $this->handleStartLiveVideo($input, $output, $helper);
                        break;
                    
                    case '6. 停止实时视频':
                        $this->handleStopLiveVideo($input, $output, $helper);
                        break;
                    
                    case '7. 开始录像回放 (Playback)':
                        $this->handleStartPlayback($input, $output, $helper);
                        break;
                    
                    case '8. 停止录像回放':
                        $this->handleStopPlayback($input, $output, $helper);
                        break;
                    
                    case '9. PTZ 云台控制':
                        $this->handlePtzControl($input, $output, $helper);
                        break;
                    
                    case '10. 查看会话信息':
                        $this->handleShowSessions($output);
                        break;
                    
                    case '0. 退出':
                        $output->writeln('<info>再见！</info>');
                        return self::SUCCESS;
                }
            } catch (\Exception $e) {
                $output->writeln("<error>错误: {$e->getMessage()}</error>");
            }
        }

        return self::SUCCESS;
    }

    /**
     * 查询设备目录
     */
    private function handleQueryCatalog(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        
        $output->writeln("<comment>正在查询设备目录...</comment>");
        $result = $this->sipClient->queryCatalog($deviceId);
        
        if ($result) {
            $output->writeln("<info>✓ 目录查询命令已发送到网关</info>");
            $output->writeln("<comment>  请在网关日志或Hook回调中查看结果</comment>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * 查询设备信息
     */
    private function handleQueryDeviceInfo(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        
        $output->writeln("<comment>正在查询设备信息...</comment>");
        $result = $this->sipClient->queryDeviceInfo($deviceId);
        
        if ($result) {
            $output->writeln("<info>✓ 设备信息查询命令已发送</info>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * 查询设备状态
     */
    private function handleQueryDeviceStatus(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        
        $output->writeln("<comment>正在查询设备状态...</comment>");
        $result = $this->sipClient->queryDeviceStatus($deviceId);
        
        if ($result) {
            $output->writeln("<info>✓ 设备状态查询命令已发送</info>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * 查询录像文件
     */
    private function handleQueryRecord(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);
        
        // 询问时间范围
        $startQuestion = new Question('请输入开始时间 (格式: 2024-12-01T00:00:00, 默认今天0点): ', date('Y-m-d') . 'T00:00:00');
        $startTime = $helper->ask($input, $output, $startQuestion);
        
        $endQuestion = new Question('请输入结束时间 (格式: 2024-12-01T23:59:59, 默认当前时间): ', date('Y-m-d\TH:i:s'));
        $endTime = $helper->ask($input, $output, $endQuestion);
        
        // 询问录像类型
        $typeQuestion = new ChoiceQuestion(
            '请选择录像类型',
            ['all' => '全部', 'time' => '定时', 'alarm' => '报警', 'manual' => '手动'],
            'all'
        );
        $type = $helper->ask($input, $output, $typeQuestion);
        
        $output->writeln("<comment>正在查询录像文件...</comment>");
        $result = $this->sipClient->queryRecord($deviceId, $channelId, $startTime, $endTime, $type);
        
        if ($result) {
            $output->writeln("<info>✓ 录像查询命令已发送</info>");
            $output->writeln("  时间范围: {$startTime} ~ {$endTime}");
            $output->writeln("  录像类型: {$type}");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * 开始实时视频
     */
    private function handleStartLiveVideo(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);
        
        // 询问 TCP 模式
        $tcpModeQuestion = new ChoiceQuestion(
            '请选择 TCP 模式',
            [
                '0' => '0. UDP (局域网)',
                '1' => '1. TCP 被动 (推荐-公网)',
                '2' => '2. TCP 主动'
            ],
            '1'
        );
        $tcpModeStr = $helper->ask($input, $output, $tcpModeQuestion);
        $tcpMode = (int)explode('.', $tcpModeStr)[0];
        
        $output->writeln("<comment>正在分配 ZLM 端口和 SSRC...</comment>");
        
        // 1. 生成 SSRC (实际应从数据库获取)
        $ssrc = $this->generateSsrc();
        
        // 2. 分配 ZLM 端口
        try {
            $portResult = $this->zlmClient->openRtpServer(0, $tcpMode);
            if (!$portResult['success']) {
                throw new \RuntimeException("ZLM 端口分配失败: " . ($portResult['error'] ?? 'Unknown error'));
            }
            $zlmPort = $portResult['port'];
            
            $output->writeln("<info>✓ ZLM 端口分配成功: {$zlmPort}</info>");
            $output->writeln("<info>✓ SSRC 生成: {$ssrc}</info>");
            
        } catch (\Exception $e) {
            $output->writeln("<error>✗ ZLM 端口分配失败: {$e->getMessage()}</error>");
            return;
        }
        
        // 3. 发送 INVITE 命令
        $output->writeln("<comment>正在发送 INVITE 命令到网关...</comment>");
        $result = $this->sipClient->startLiveVideo($deviceId, $channelId, $ssrc, $zlmPort, $tcpMode);
        
        if ($result) {
            $sessionKey = "{$deviceId}:{$channelId}:live";
            $this->sessionData[$sessionKey] = [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'type' => 'live',
                'ssrc' => $ssrc,
                'zlm_port' => $zlmPort,
                'tcp_mode' => $tcpMode,
                'started_at' => date('Y-m-d H:i:s')
            ];
            
            $output->writeln("<info>✓ 实时视频命令已发送</info>");
            $output->writeln("  设备ID: {$deviceId}");
            $output->writeln("  通道ID: {$channelId}");
            $output->writeln("  SSRC: {$ssrc}");
            $output->writeln("  ZLM端口: {$zlmPort}");
            $output->writeln("  TCP模式: {$tcpMode} (" . $this->getTcpModeName($tcpMode) . ")");
            $output->writeln('');
            $output->writeln("<comment>⚠ 注意：设备响应后需要调用 ZLM 的 updateRtpServerSsrc 更新实际SSRC</comment>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
            // 释放端口
            $this->zlmClient->closeRtpServer(0, $zlmPort);
        }
    }

    /**
     * 停止实时视频
     */
    private function handleStopLiveVideo(InputInterface $input, OutputInterface $output, $helper): void
    {
        if (empty($this->sessionData)) {
            $output->writeln("<comment>当前没有活跃的视频会话</comment>");
            return;
        }
        
        // 列出活跃会话
        $liveSessions = array_filter($this->sessionData, fn($s) => $s['type'] === 'live');
        if (empty($liveSessions)) {
            $output->writeln("<comment>当前没有活跃的实时视频会话</comment>");
            return;
        }
        
        $output->writeln("<info>活跃的实时视频会话：</info>");
        $choices = [];
        foreach ($liveSessions as $key => $session) {
            $desc = "{$session['channel_id']} (端口: {$session['zlm_port']}, 开始: {$session['started_at']})";
            $choices[$key] = $desc;
            $output->writeln("  - {$desc}");
        }
        
        $sessionQuestion = new ChoiceQuestion('请选择要停止的会话', $choices);
        $selectedKey = $helper->ask($input, $output, $sessionQuestion);
        
        $session = $this->sessionData[$selectedKey];
        
        $output->writeln("<comment>正在停止实时视频...</comment>");
        $result = $this->sipClient->stopLiveVideo($session['device_id'], $session['channel_id']);
        
        if ($result) {
            // 关闭 ZLM 端口
            $this->zlmClient->closeRtpServer(0, $session['zlm_port']);
            
            unset($this->sessionData[$selectedKey]);
            
            $output->writeln("<info>✓ 停止命令已发送</info>");
            $output->writeln("<info>✓ ZLM 端口已释放: {$session['zlm_port']}</info>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * 开始录像回放
     */
    private function handleStartPlayback(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);
        
        // 询问时间范围
        $startQuestion = new Question('请输入回放开始时间 (格式: 2024-12-01T08:00:00): ');
        $startTime = $helper->ask($input, $output, $startQuestion);
        
        $endQuestion = new Question('请输入回放结束时间 (格式: 2024-12-01T10:00:00): ');
        $endTime = $helper->ask($input, $output, $endQuestion);
        
        // 询问 TCP 模式
        $tcpModeQuestion = new ChoiceQuestion(
            '请选择 TCP 模式',
            ['0' => 'UDP', '1' => 'TCP 被动 (推荐)', '2' => 'TCP 主动'],
            '1'
        );
        $tcpModeStr = $helper->ask($input, $output, $tcpModeQuestion);
        $tcpMode = (int)$tcpModeStr;
        
        $output->writeln("<comment>正在分配 ZLM 端口和 SSRC...</comment>");
        
        // 生成 SSRC 和分配端口
        $ssrc = $this->generateSsrc();
        
        try {
            $portResult = $this->zlmClient->openRtpServer(0, $tcpMode);
            if (!$portResult['success']) {
                throw new \RuntimeException("ZLM 端口分配失败");
            }
            $zlmPort = $portResult['port'];
            
            $output->writeln("<info>✓ ZLM 端口: {$zlmPort}, SSRC: {$ssrc}</info>");
            
        } catch (\Exception $e) {
            $output->writeln("<error>✗ {$e->getMessage()}</error>");
            return;
        }
        
        $output->writeln("<comment>正在发送回放 INVITE 命令...</comment>");
        $result = $this->sipClient->startPlayback(
            $deviceId, 
            $channelId, 
            $startTime, 
            $endTime,
            $ssrc,
            $zlmPort,
            $tcpMode
        );
        
        if ($result) {
            $sessionKey = "{$deviceId}:{$channelId}:playback";
            $this->sessionData[$sessionKey] = [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'type' => 'playback',
                'ssrc' => $ssrc,
                'zlm_port' => $zlmPort,
                'tcp_mode' => $tcpMode,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'started_at' => date('Y-m-d H:i:s')
            ];
            
            $output->writeln("<info>✓ 录像回放命令已发送</info>");
            $output->writeln("  回放时间: {$startTime} ~ {$endTime}");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
            $this->zlmClient->closeRtpServer(0, $zlmPort);
        }
    }

    /**
     * 停止录像回放
     */
    private function handleStopPlayback(InputInterface $input, OutputInterface $output, $helper): void
    {
        $playbackSessions = array_filter($this->sessionData, fn($s) => $s['type'] === 'playback');
        
        if (empty($playbackSessions)) {
            $output->writeln("<comment>当前没有活跃的回放会话</comment>");
            return;
        }
        
        $choices = [];
        foreach ($playbackSessions as $key => $session) {
            $choices[$key] = "{$session['channel_id']} ({$session['start_time']} ~ {$session['end_time']})";
        }
        
        $sessionQuestion = new ChoiceQuestion('请选择要停止的回放会话', $choices);
        $selectedKey = $helper->ask($input, $output, $sessionQuestion);
        
        $session = $this->sessionData[$selectedKey];
        
        $result = $this->sipClient->stopPlayback($session['device_id'], $session['channel_id']);
        
        if ($result) {
            $this->zlmClient->closeRtpServer(0, $session['zlm_port']);
            unset($this->sessionData[$selectedKey]);
            
            $output->writeln("<info>✓ 回放已停止</info>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * PTZ 云台控制
     */
    private function handlePtzControl(InputInterface $input, OutputInterface $output, $helper): void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);
        
        $commandQuestion = new ChoiceQuestion(
            '请选择 PTZ 控制命令',
            [
                'up' => '向上',
                'down' => '向下',
                'left' => '向左',
                'right' => '向右',
                'zoom_in' => '放大',
                'zoom_out' => '缩小',
                'stop' => '停止'
            ],
            'stop'
        );
        $command = $helper->ask($input, $output, $commandQuestion);
        
        $speedQuestion = new Question('请输入速度 (1-255, 默认 5): ', '5');
        $speed = (int)$helper->ask($input, $output, $speedQuestion);
        
        $output->writeln("<comment>正在发送 PTZ 控制命令...</comment>");
        $result = $this->sipClient->ptzControl($deviceId, $channelId, $command, $speed);
        
        if ($result) {
            $output->writeln("<info>✓ PTZ 命令已发送: {$command}, 速度: {$speed}</info>");
        } else {
            $output->writeln("<error>✗ 发送失败</error>");
        }
    }

    /**
     * 查看会话信息
     */
    private function handleShowSessions(OutputInterface $output): void
    {
        if (empty($this->sessionData)) {
            $output->writeln("<comment>当前没有活跃的会话</comment>");
            return;
        }
        
        $output->writeln("<info>活跃的会话列表：</info>");
        $output->writeln('');
        
        foreach ($this->sessionData as $key => $session) {
            $output->writeln("  <fg=cyan>[{$session['type']}]</> {$key}");
            $output->writeln("    设备ID: {$session['device_id']}");
            $output->writeln("    通道ID: {$session['channel_id']}");
            $output->writeln("    SSRC: {$session['ssrc']}");
            $output->writeln("    ZLM端口: {$session['zlm_port']}");
            $output->writeln("    TCP模式: {$session['tcp_mode']} (" . $this->getTcpModeName($session['tcp_mode']) . ")");
            $output->writeln("    开始时间: {$session['started_at']}");
            
            if ($session['type'] === 'playback') {
                $output->writeln("    回放时间: {$session['start_time']} ~ {$session['end_time']}");
            }
            
            $output->writeln('');
        }
    }

    /**
     * 询问设备ID
     */
    private function askDeviceId(InputInterface $input, OutputInterface $output, $helper, string $default = '34020000001320948622'): string
    {
        $question = new Question("请输入设备ID (20位, 默认 {$default}): ", $default);
        $question->setValidator(function ($answer) {
            if (!preg_match('/^\d{20}$/', $answer)) {
                throw new \RuntimeException('设备ID必须是20位数字');
            }
            return $answer;
        });
        
        return $helper->ask($input, $output, $question);
    }

    /**
     * 询问通道ID
     */
    private function askChannelId(InputInterface $input, OutputInterface $output, $helper, string $defaultDeviceId): string
    {
        $default = str_replace('948622', '000001', $defaultDeviceId);  // 简单推算通道ID
        $question = new Question("请输入通道ID (20位, 默认 {$default}): ", $default);
        $question->setValidator(function ($answer) {
            if (!preg_match('/^\d{20}$/', $answer)) {
                throw new \RuntimeException('通道ID必须是20位数字');
            }
            return $answer;
        });
        
        return $helper->ask($input, $output, $question);
    }

    /**
     * 生成 SSRC (测试用，实际应从数据库获取)
     */
    private function generateSsrc(): string
    {
        return str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    }

    /**
     * 获取 TCP 模式名称
     */
    private function getTcpModeName(int $mode): string
    {
        return match($mode) {
            0 => 'UDP',
            1 => 'TCP被动',
            2 => 'TCP主动',
            default => '未知'
        };
    }
}
