<?php

error_reporting(E_ALL);
//set_time_limit(0);

function env_get($key)
{
    $envFile = dirname(dirname(__DIR__)) . '/.env';
    if (is_file($envFile)) {
        $values = array_filter(explode(PHP_EOL, file_get_contents($envFile)));
        foreach ($values as $value) {
            $item = explode('=', $value);
            if ($key === $item[0]) {
                return $item[1];
            }
        }
    }

    return null;
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';

$sysRefererWhiteStr = env_get('BIG_FILE_DOWNLOAD_REFERER_WHITE_LIST');

$refererWhiteList = array_merge([
    'http://localhost:9999/'
], !empty($sysRefererWhiteStr) ? implode('|', $sysRefererWhiteStr) : []);

if (!in_array($referer, $refererWhiteList) || empty($_SERVER['HTTP_X_AUTH_TOKEN']) || empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    header("HTTP/1.1 403 Forbidden");
    exit;
}

// 要下载的文件 URL
$path = $_GET['path'] ?? '';
if (empty($path)) {
    http_response_code(413);
    header("Content-Type:application/json;charset=utf-8");
    exit(json_encode([
        'code' => -1,
        'data' => null,
        'message' => '缺少必要参数'
    ]));
}

$file = dirname(__DIR__) . '/' . $path;
if (!is_file($file)) {
    http_response_code(413);
    header("Content-Type:application/json;charset=utf-8");
    exit(json_encode([
        'code' => -1,
        'data' => null,
        'message' => '缺少必要参数'
    ]));
}

try {
    $fileSize = filesize($file);
// 设置分段大小（字节）
    $chunkSize = 1 * 1024 * 1024; // 1MB
    if ($fileSize > 2 * 1024 * 1024 * 1024) {
        http_response_code(400);
        header("Content-Type:application/json;charset=utf-8");
        exit(json_encode([
            'code' => -1,
            'data' => null,
            'message' => '文件太大，无法下载'
        ]));
    }
    $pathItems = explode('/', $path);
    $filename = $pathItems[count($pathItems) - 1];
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        $range = str_replace('bytes=', '', $range);
        $range = explode('-', $range);
        $start = intval($range[0]);
        $end = $fileSize - 1;

        if (isset($range[1]) && !empty($range[1])) {
            $end = intval($range[1]);
        }

        // 设置 HTTP 状态码为 206 Partial Content
        http_response_code(206);

        // 设置 Content-Range 头
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);

        // 设置 Content-Length 头
        header('Content-Length: ' . ($end - $start + 1));
    } else {
        // 设置 HTTP 状态码为 200 OK
        http_response_code(200);
        $start = 0;
        $end = $fileSize - 1;
        // 设置 Content-Length 头
        header('Content-Length: ' . ($end - $start + 1));
    }

// 打开文件
    $fileHandle = fopen($file, 'rb');
// 定位到分段的起始位置
    fseek($fileHandle, $start);
// 分段读取并输出文件内容
    file_put_contents('chunk.log', 'start=' . $start . 'end=' . $end . PHP_EOL, FILE_APPEND);
    while (!feof($fileHandle) && $start <= $end) {
        $chunkSize = ($end - $start < $chunkSize) ? $end - $start + 1 : $chunkSize;
        file_put_contents('chunk_size.log', $chunkSize . PHP_EOL, FILE_APPEND);
        $content = fread($fileHandle, $chunkSize);
        echo $content;
        $start += $chunkSize;
        ob_flush();
        flush();
//    usleep(1000000 * 0.2);
    }

    ob_end_clean();
// 关闭文件句柄
    fclose($fileHandle);
} catch (Exception $e) {
    http_response_code(500);
    header("Content-Type:application/json;charset=utf-8");
    exit(json_encode([
        'code' => -1,
        'data' => null,
        'message' => '服务器内部错误'
    ]));
}
exit;
