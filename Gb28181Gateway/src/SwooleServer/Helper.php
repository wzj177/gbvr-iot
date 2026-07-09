<?php

namespace Gb28181Gateway\src\SwooleServer;

use Swoole\Coroutine\Http\Client as CoHttpClient;

class Helper
{
    /**
     * 兼容 GB2312 编码的 SimpleXML 解析函数
     * @param string $body
     * @return \SimpleXMLElement|null
     */
    public static function gb_safe_xml_parse(string $body) : ?\SimpleXMLElement
    {
        // 移除XML声明中的encoding属性（如 encoding="GB2312"、encoding="UTF-8" 等）
        $cleaned = preg_replace('/encoding\s*=\s*["\'][^"\']*["\']/i', '', $body, 1);
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string((string)$cleaned);
        libxml_clear_errors();
        return $xml === false ? null : $xml;
    }

    /**
     * 取 SimpleXMLElement 节点的文本，节点不存在返回空字符串
     * @param $node
     * @return string
     */
    public static function gb_xml_text($node) : string
    {
        if ($node === null) return '';
        if ($node instanceof \SimpleXMLElement) {
            return trim((string)$node);
        }
        return '';
    }

    /**
     * root->find('Tag') 的等价实现：只取第一层直接子节点
     * @param \SimpleXMLElement|null $root
     * @param string $tag
     * @return \SimpleXMLElement|null
     */
    public static function gb_xml_child(?\SimpleXMLElement $root, string $tag) : ?\SimpleXMLElement
    {
        if ($root === null) return null;
        if (isset($root->{$tag})) {
            $n = $root->{$tag};
            return ($n instanceof \SimpleXMLElement) ? $n : null;
        }
        return null;
    }

    /**
     * root->findall('.//Item') 的等价实现：递归查找所有层级的 <Item> 节点
     * @param \SimpleXMLElement|null $root
     * @param string $tag
     * @return array
     */
    public static function gb_xml_find_all(?\SimpleXMLElement $root, string $tag) : array
    {
        if ($root === null) return [];
        $result = $root->xpath('//' . $tag);
        return $result === false ? [] : $result;
    }

    /**
     * POST JSON
     * @param string $url
     * @param array $params
     * @param float $timeout
     * @return array
     */
    public static function gb_http_post_json(string $url, array $params, float $timeout = 5.0) : array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return [false, 0, '', 'invalid url: ' . $url];
        }
        $ssl = ($parts['scheme'] ?? 'http') === 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? ('?' . $parts['query']) : '');

        $cli = new CoHttpClient($host, $port, $ssl);
        $cli->set(['timeout' => $timeout]);
        $cli->setHeaders(['Content-Type' => 'application/json;']);
        try {
            $ok = $cli->post($path, (string)json_encode($params));
            if (!$ok) {
                $errMsg = $cli->errMsg ? : 'connection error';
                $cli->close();
                return [false, 0, '', $errMsg];
            }
            $status = $cli->statusCode;
            $body = (string)$cli->body;
            $cli->close();
            return [true, $status, $body, null];
        } catch (\Throwable $e) {
            $cli->close();
            return [false, 0, '', $e->getMessage()];
        }
    }


    /**
     * 生成 UUID v4
     * @return string
     * @throws \Exception
     */
    public static function gb_uuid4() : string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}