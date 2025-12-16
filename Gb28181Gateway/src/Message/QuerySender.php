<?php

namespace Gb28181\GateWay\Message;

use \SimpleXMLElement;
use Gb28181\GateWay\Libs\Logger;

/**
 * GB28181 查询发送器
 * 用于主动向设备发送查询和控制命令
 */
class QuerySender
{
    private $sipServer;
    private array $config;
    private int $sn = 1;
    private Logger $logger;

    public function __construct($sipServer, array $config = [])
    {
        $this->sipServer = $sipServer;
        $this->config = array_merge([
            'server_id' => '34020000002000000001',
            'server_domain' => '3402000000',
            'debug' => false,
        ], $config);
        $this->logger = Logger::getInstance();
    }

    /**
     * 生成序列号
     */
    private function generateSN(): int
    {
        $this->sn++;
        if ($this->sn > 99999999) {
            $this->sn = 1;
        }
        return $this->sn;
    }

    /**
     * 构建查询 XML
     */
    private function buildQueryXml(string $cmdType, string $deviceId): string
    {
        $sn = $this->generateSN();

        // 手动构建 XML，确保格式正确
        $xml = '<?xml version="1.0" encoding="GB2312"?>' . "\r\n";
        $xml .= '<Query>' . "\r\n";
        $xml .= '<CmdType>' . $cmdType . '</CmdType>' . "\r\n";
        $xml .= '<SN>' . $sn . '</SN>' . "\r\n";
        $xml .= '<DeviceID>' . $deviceId . '</DeviceID>' . "\r\n";
        $xml .= '</Query>';

        return $xml;
    }

    /**
     * 构建控制 XML
     */
    private function buildControlXml(string $cmdType, string $deviceId, array $params): string
    {
        $sn = $this->generateSN();
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"GB2312\"?><Control></Control>");
        $xml->addChild('CmdType', $cmdType);
        $xml->addChild('SN', (string)$sn);
        $xml->addChild('DeviceID', $deviceId);

        // 添加额外参数
        foreach ($params as $key => $value) {
            $xml->addChild($key, (string)$value);
        }

        return $xml->asXML();
    }

    /**
     * 发送目录查询
     */
    public function queryCatalog(string $deviceUri, string $deviceId): bool
    {
        $xml = $this->buildQueryXml('Catalog', $deviceId);

        if ($this->config['debug']) {
            error_log("[DEBUG] QuerySender sending Catalog query to: {$deviceUri}");
            error_log("[DEBUG] XML Body:\n{$xml}");
        }

        $result = $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');

        if ($this->config['debug']) {
            error_log("[DEBUG] sendMessage result: " . ($result ? 'true' : 'false'));
        }

        return $result;
    }

    /**
     * 发送设备信息查询
     */
    public function queryDeviceInfo(string $deviceUri, string $deviceId): bool
    {
        $xml = $this->buildQueryXml('DeviceInfo', $deviceId);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 发送设备状态查询
     */
    public function queryDeviceStatus(string $deviceUri, string $deviceId): bool
    {
        $xml = $this->buildQueryXml('DeviceStatus', $deviceId);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 发送录像文件查询
     */
    public function queryRecordInfo(string $deviceUri, string $deviceId, string $startTime, string $endTime, string $type = 'all'): bool
    {
        $sn = $this->generateSN();
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"GB2312\"?><Query></Query>");
        $xml->addChild('CmdType', 'RecordInfo');
        $xml->addChild('SN', (string)$sn);
        $xml->addChild('DeviceID', $deviceId);
        $xml->addChild('StartTime', $startTime);
        $xml->addChild('EndTime', $endTime);
        $xml->addChild('Type', $type);

        return $this->sipServer->sendMessage($deviceUri, $xml->asXML(), 'Application/MANSCDP+xml');
    }

    /**
     * PTZ 控制
     */
    public function ptzControl(string $deviceUri, string $channelId, string $ptzCmd): bool
    {
        $xml = $this->buildControlXml('DeviceControl', $channelId, [
            'PTZCmd' => $ptzCmd
        ]);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 设备控制（重启、复位等）
     */
    public function deviceControl(string $deviceUri, string $deviceId, string $controlType, array $params = []): bool
    {
        $params['ControlType'] = $controlType;
        $xml = $this->buildControlXml('DeviceControl', $deviceId, $params);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }
}
