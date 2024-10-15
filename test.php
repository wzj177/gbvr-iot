<?php

function isHexUUID($hexUUID) {
    // 去除字符串中的空格和破折号
    $cleanedUUID = str_replace([' ', '-'], '', $hexUUID);

    // 将十六进制 UUID 转换为标准格式
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($cleanedUUID, 4));

    // 验证 UUID 是否符合标准格式
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
}

// 要验证的经过转换成十六进制的 UUID
$hexUUIDToCheck = '1ee9a275e19f6b30b619f69c4e2a9c72';

// 检查转换后的 UUID 是否符合格式
$isValidHexUUID = isHexUUID($hexUUIDToCheck);

if ($isValidHexUUID) {
    echo '这是一个有效的 UUID';
} else {
    echo '这不是一个有效的 UUID';
}
$timestamp = '1705302445';
$nonce = '222222';
$secret = "8881db7f3d59f90d081ea41d7c659dbe";
$partner_user_id = "015873ef-3192-42cb-917f-07481cf41fa0";
$array = [$secret, $timestamp, $nonce, $partner_user_id];
sort($array, SORT_STRING);
print_r($array);
$signature = md5(md5(implode($array)));

echo PHP_EOL, 'signature=', $signature, PHP_EOL;

//use Webman\App;
//
//require_once __DIR__ . '/vendor/workerman/webman-framework/src/App.php';
//require_once __DIR__ . '/vendor/workerman/webman-framework/src/Util.php';
//
//$pathExplode = explode('/', trim('/api/v1/auth', '/'));
//$action = 'index';
//$classPrefix = '';
//$suffix = '';
//$t = microtime(true);
//$i = $j = 1;
//App::$appPath = __DIR__ . '/app';
//for($k=0; $k<$i; $k++) {
//    App::guessControllerAction($pathExplode, $action, $suffix, $classPrefix);
//}
//
//echo $j/(microtime(true) - $t) , "\n";
//
//
//
//$chunkNumber = $_POST['chunkNumber']; // 从请求中获取分片号
//$fileData = $_POST['fileData']; // 从请求中获取分片数据
//
//$tmpCache = 'tmp/cache.tmp'; // 临时缓存文件路径
//$tmpFile = 'tmp/file.tmp'; // 临时文件路径
//
//$cacheData = file_get_contents($tmpCache); // 读取临时缓存数据
//$cacheArray = unserialize($cacheData); // 反序列化缓存数组
//
//// 存储分片数据到临时缓存数组的相应索引位置
//$cacheArray[$chunkNumber] = $fileData;
//
//$serializedCache = serialize($cacheArray); // 序列化缓存数组
//file_put_contents($tmpCache, $serializedCache); // 将缓存数据写入临时缓存文件
//
//// 判断是否所有分片都已上传
//if (count($cacheArray) === $totalChunks) {
//    // 所有分片已上传，按照索引号顺序读取缓存数组的分片数据并写入 tmp file
//    ksort($cacheArray); // 按照索引号排序
//    foreach ($cacheArray as $chunkData) {
//        file_put_contents($tmpFile, $chunkData, FILE_APPEND); // 追加写入 tmp file
//    }
//
//    // 完成合并操作，清理临时缓存文件
//    unlink($tmpCache);
//}
