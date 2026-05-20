<?php

function preg_find_dir_files($path, $pattern, $sort = false)
{
    $files = [];
    foreach (glob("{$path}/{$pattern}") as $filename) {
        $files[] = $filename;
    }

    if ($sort) {
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    }

    return $files;
}

function is_win_os()
{
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/**
 * 判断是否是本地客户端
 * @param $ip
 * @param $ipFilters
 * @return bool
 */
function is_local_client($ip, $ipFilters
= [
    '127.0.0.1',
    '0.0.0.0',
    '192.168.*.*',
]) : bool
{
    if (empty($ipFilters)) {
        return true;
    }
    foreach ($ipFilters as $filter) {
        if ($filter === '*' || $filter === $ip || (($pos = strpos($filter, '*')) !== false && !strncmp($ip, $filter, $pos)))
            return true;
    }

    return false;
}

function is_https_request()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;

    }

    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }

    if (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
        return true;
    }

    return false;
}

function uploads_path(string $path = '')
{
    $rootPath = public_path() . DIRECTORY_SEPARATOR . 'uploads';
    if (!empty($path)) {
        $path = ltrim($path, '/');
        $rootPath .= DIRECTORY_SEPARATOR . $path;
    }

    return $rootPath;
}

function static_assets_path()
{
    return public_path() . DIRECTORY_SEPARATOR . 'api-static';
}

/**
 * CoreW  path
 * @param string $path
 * @return string
 */
function core_path(string $path = '') : string
{
    static $corePath = '';
    if (!$corePath) {
        $corePath = run_path('CoreW');
    }

    return path_combine($corePath, $path);
}