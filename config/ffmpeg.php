<?php

return [
    'ffmpeg_bin'  => env('FFMPEG_BIN', null),
    'ffprobe_bin' => env('FFPROBE_BIN', null),
    'timeout'     => env('FFMPEG_TIMEOUT', 3600),
    'threads'     => env('FFMPEG_THREADS', 12),
    'log_file'    => dirname(__DIR__) . '/runtime/ffmpeg/logs/' . date('Ymd') . '.log',
];