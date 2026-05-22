<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Core;
use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Redis;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class GB28181Test extends Command
{
    protected static $defaultName = 'gb:test';
    protected static $defaultDescription = 'GB28181 Interactive Testing Tool';

    private Gb28181Service $gb28181Service;
    private DeviceService $deviceService;  // 添加DeviceService依赖

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
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $bfw = Core::initCiBiz();
        $this->gb28181Service = new Gb28181Service($bfw);
        $this->deviceService = $bfw->service('Devices:DeviceService');  // 初始化DeviceService

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
                    '11' => '11. 预置位管理',
                    '12' => '12. 设备升级 (2022)',
                    '13' => '13. 图像抓拍 (2022)',
                    '14' => '14. 订阅设备位置 (MobilePosition)',
                    '15' => '15. 取消位置订阅',
                    '16' => '16. 查询通道音视频信息',
                    '0'  => '0. 退出',
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
                        //                        $this->handleStartLiveVideo($input, $output, $helper);
                        break;

                    case '6. 停止实时视频':
                        //                        $this->handleStopLiveVideo($input, $output, $helper);
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

                    case '11. 预置位管理':
                        $this->handlePresetManagement($input, $output, $helper);
                        break;

                    case '12. 设备升级 (2022)':
                        $this->handleDeviceUpgrade($input, $output, $helper);
                        break;

                    case '13. 图像抓拍 (2022)':
                        $this->handleSnapshot($input, $output, $helper);
                        break;

                    case '14. 订阅设备位置 (MobilePosition)':
                        $this->handleMobilePositionSubscription($input, $output, $helper);
                        break;
                    case '15. 取消位置订阅':
                        $this->handleUnsubscribeMobilePosition($input, $output, $helper);
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
    private function handleQueryCatalog(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);

        $output->writeln("<comment>正在查询设备目录...</comment>");
        try {
            $result = $this->gb28181Service->queryCatalog($deviceId);

            if ($result) {
                $output->writeln("<info>✓ 目录查询命令已发送到网关</info>");
                $output->writeln("<comment>  请在网关日志或Hook回调中查看结果</comment>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * 查询设备信息
     */
    private function handleQueryDeviceInfo(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);

        $output->writeln("<comment>正在查询设备信息...</comment>");
        try {
            $result = $this->gb28181Service->queryDeviceInfo($deviceId);

            if ($result) {
                $output->writeln("<info>✓ 设备信息查询命令已发送</info>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * 查询设备状态
     */
    private function handleQueryDeviceStatus(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);

        $output->writeln("<comment>正在查询设备状态...</comment>");
        try {
            $result = $this->gb28181Service->queryDeviceStatus($deviceId);

            if ($result) {
                $output->writeln("<info>✓ 设备状态查询命令已发送</info>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * 查询录像文件
     */
    private function handleQueryRecord(InputInterface $input, OutputInterface $output, $helper) : void
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
        try {
            $result = $this->gb28181Service->queryRecord($deviceId, $channelId, $startTime, $endTime, $type);

            if ($result) {
                $output->writeln("<info>✓ 录像查询命令已发送</info>");
                $output->writeln("  时间范围: {$startTime} ~ {$endTime}");
                $output->writeln("  录像类型: {$type}");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * 开始实时视频
     */

    /**
     * 停止实时视频
     */

    /**
     * 开始录像回放
     */
    private function handleStartPlayback(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);
        $channel = $this->deviceService->getChannelByDeviceAndChannel($deviceId, $channelId);
        if (!$channel) {
            $output->writeln("<error>设备通道不存在，请确认设备已经注册</error>");
            return;
        }
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

        // 创建回放会话
        try {
            $sessionResult = $this->gb28181Service->createPlaybackSessionAndOpenRtp($deviceId, $channelId, $startTime, $endTime, $tcpMode);
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 创建回放会话失败: {$e->getMessage()}</error>");
            return;
        }

        if (!$sessionResult) {
            $output->writeln("<error>✗ 创建回放会话失败</error>");
            return;
        }

        $playbackStreamId = $sessionResult['stream_id'];
        $playbackSsrc = $sessionResult['ssrc'];
        $zlmPort = $sessionResult['rtp_port'];

        $output->writeln("<info>✓ ZLM 端口: {$zlmPort}, SSRC: {$playbackSsrc}</info>");
        $output->writeln("<info>✓ Stream ID: {$playbackStreamId}</info>");

        $output->writeln("<comment>正在发送回放 INVITE 命令...</comment>");
        try {
            $result = $this->gb28181Service->startPlayback(
                $deviceId,
                $channelId,
                $startTime,
                $endTime,
                $playbackSsrc,
                $zlmPort,
                $tcpMode,
                $playbackStreamId
            );

            if ($result) {
                $output->writeln("<info>✓ 录像回放命令已发送</info>");
                $output->writeln("  回放时间: {$startTime} ~ {$endTime}");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
                $this->gb28181Service->closeRtpServer($playbackStreamId);
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
            $this->gb28181Service->closeRtpServer($playbackStreamId);
        }
    }

    /**
     * 停止录像回放
     */
    private function handleStopPlayback(InputInterface $input, OutputInterface $output, $helper) : void
    {
        // 从数据库获取回放会话
        $playbackSessions = [];
        // 这里简化处理，实际应用中应该根据条件查询数据库中的回放会话

        if (empty($playbackSessions)) {
            $output->writeln("<comment>当前没有活跃的回放会话</comment>");
            return;
        }

        $choices = [];
        foreach ($playbackSessions as $session) {
            $choices[$session['id']] = "{$session['channel_id']} ({$session['start_time']} ~ {$session['end_time']})";
        }

        $sessionQuestion = new ChoiceQuestion('请选择要停止的回放会话', $choices);
        $selectedSessionId = $helper->ask($input, $output, $sessionQuestion);

        $session = $this->deviceService->getSessionById($selectedSessionId);

        try {
            $result = $this->gb28181Service->stopPlayback($session['device_id'], $session['channel_id']);
            if ($result) {
                $this->gb28181Service->closeRtpServer($session['stream_id']);

                // 更新会话状态
                $this->deviceService->updateSession($session['id'], ['status' => 'stopped', 'updated_at' => date('Y-m-d H:i:s')]);

                $output->writeln("<info>✓ 回放已停止</info>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * PTZ 云台控制
     *
     * 注意: PTZ控制需要配合stop命令使用
     * - 前端实现: 鼠标按下发送move命令，鼠标松开发送stop命令
     * - 后端提供: ptzControl() 和 ptzStop() 两个方法
     */
    private function handlePtzControl(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);

        $commandQuestion = new ChoiceQuestion(
            '请选择 PTZ 控制命令',
            [
                'up'       => '向上',
                'down'     => '向下',
                'left'     => '向左',
                'right'    => '向右',
                'zoom_in'  => '放大',
                'zoom_out' => '缩小',
                'stop'     => '停止',
            ],
            'stop'
        );
        $command = $helper->ask($input, $output, $commandQuestion);

        if ($command === 'stop') {
            // 使用专门的stop方法
            $output->writeln("<comment>正在发送停止命令...</comment>");
            try {
                $result = $this->gb28181Service->ptzStop($deviceId, $channelId);

                if ($result) {
                    $output->writeln("<info>✓ PTZ 停止命令已发送</info>");
                } else {
                    $output->writeln("<error>✗ 发送失败</error>");
                }
            } catch (\Exception $e) {
                $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
            }
            return;
        }

        $speedQuestion = new Question('请输入速度 (1-255, 默认 5): ', '5');
        $speed = (int)$helper->ask($input, $output, $speedQuestion);

        $output->writeln("<comment>正在发送 PTZ 控制命令...</comment>");
        $output->writeln("<comment>💡 提示: 实际应用中应在鼠标松开时调用 ptzStop()</comment>");
        try {
            $result = $this->gb28181Service->ptzControl($deviceId, $channelId, $command, $speed);

            if ($result) {
                $output->writeln("<info>✓ PTZ 命令已发送: {$command}, 速度: {$speed}</info>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * 查看会话信息
     */
    private function handleShowSessions(OutputInterface $output) : void
    {
        // 从数据库获取所有活跃会话
        // 这里简化处理，实际应用中应该查询数据库中的会话数据
        $sessions = [];

        if (empty($sessions)) {
            $output->writeln("<comment>当前没有活跃的会话</comment>");
            return;
        }

        $output->writeln("<info>活跃的会话列表：</info>");
        $output->writeln('');

        foreach ($sessions as $session) {
            $output->writeln("  <fg=cyan>[{$session['type']}]</> Session ID: {$session['id']}");
            $output->writeln("    设备ID: {$session['device_id']}");
            $output->writeln("    通道ID: {$session['channel_id']}");
            $output->writeln("    SSRC: {$session['ssrc']}");
            $output->writeln("    ZLM端口: {$session['rtp_port']}");
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
    private function askDeviceId(InputInterface $input, OutputInterface $output, $helper, string $default = '34020000001320948622') : string
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


    private function askStreamId(InputInterface $input, OutputInterface $output, $helper) : string
    {
        $question = new Question("请输入流ID: ");

        return $helper->ask($input, $output, $question);
    }

    /**
     * 询问通道ID
     */
    private function askChannelId(InputInterface $input, OutputInterface $output, $helper, string $defaultDeviceId) : string
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
     * 获取 TCP 模式名称
     */
    private function getTcpModeName(int $mode) : string
    {
        return match ($mode) {
            0 => 'UDP',
            1 => 'TCP被动',
            2 => 'TCP主动',
            default => '未知'
        };
    }

    /**
     * 预置位管理
     */
    private function handlePresetManagement(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);

        $output->writeln('');
        $output->writeln('<fg=cyan>预置位操作：</>');

        $actionQuestion = new ChoiceQuestion(
            '请选择操作',
            [
                'set'    => '设置预置位',
                'call'   => '调用预置位',
                'delete' => '删除预置位',
            ],
            'call'
        );
        $action = $helper->ask($input, $output, $actionQuestion);

        $presetQuestion = new Question('请输入预置位编号 (1-255, 默认 1): ', '1');
        $presetId = (int)$helper->ask($input, $output, $presetQuestion);

        $output->writeln("<comment>正在执行预置位操作: {$action}, 编号: {$presetId}...</comment>");

        try {
            $result = match ($action) {
                'set' => $this->gb28181Service->presetSet($deviceId, $channelId, $presetId),
                'call' => $this->gb28181Service->presetCall($deviceId, $channelId, $presetId),
                'delete' => $this->gb28181Service->presetDelete($deviceId, $channelId, $presetId),
                default => false
            };


            if ($result) {
                $output->writeln("<info>✓ 预置位命令已发送: {$action}, 编号: {$presetId}</info>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * GB28181-2022: 设备升级
     */
    private function handleDeviceUpgrade(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);

        $output->writeln('');
        $output->writeln('<fg=cyan>设备升级参数 (GB/T 28181-2022)：</>');

        $manufacturerQuestion = new Question('请输入制造商 (默认 Hikvision): ', 'Hikvision');
        $manufacturer = $helper->ask($input, $output, $manufacturerQuestion);

        $firmwareQuestion = new Question('请输入固件版本 (例如 V5.7.12_build230801): ');
        $firmware = $helper->ask($input, $output, $firmwareQuestion);

        if (!$firmware) {
            $output->writeln("<error>固件版本不能为空</error>");
            return;
        }

        $output->writeln("<comment>正在发送升级命令...</comment>");
        $output->writeln("<comment>⚠ 注意：设备升级前会注销，升级完成后会重新注册</comment>");

        try {
            $result = $this->gb28181Service->deviceUpgrade($deviceId, $manufacturer, $firmware);

            if ($result) {
                $output->writeln("<info>✓ 设备升级命令已发送</info>");
                $output->writeln("  制造商: {$manufacturer}");
                $output->writeln("  固件版本: {$firmware}");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }

    /**
     * GB28181-2022: 图像抓拍
     */
    private function handleSnapshot(InputInterface $input, OutputInterface $output, $helper) : void
    {
        $deviceId = $this->askDeviceId($input, $output, $helper);
        $channelId = $this->askChannelId($input, $output, $helper, $deviceId);

        $output->writeln('');
        $output->writeln('<fg=cyan>图像抓拍参数 (GB/T 28181-2022)：</>');

        $formatQuestion = new ChoiceQuestion(
            '请选择图片格式',
            ['JPEG', 'PNG', 'BMP'],
            'JPEG'
        );
        $imageFormat = $helper->ask($input, $output, $formatQuestion);

        $output->writeln("<comment>正在发送抓拍命令...</comment>");

        try {
            $result = $this->gb28181Service->snapshot($deviceId, $channelId, $imageFormat);

            if ($result) {
                $output->writeln("<info>✓ 图像抓拍命令已发送</info>");
                $output->writeln("  通道ID: {$channelId}");
                $output->writeln("  图片格式: {$imageFormat}");
                $output->writeln("  Session ID: {$result['session_id']}");
                $output->writeln('');
                $output->writeln("<comment>⚠ 设备抓拍完成后会发送 MediaStatus 通知，包含图片URL</comment>");
            } else {
                $output->writeln("<error>✗ 发送失败</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>✗ 发送失败: {$e->getMessage()}</error>");
        }
    }
}