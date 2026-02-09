<?php

namespace CoreW\Business\Devices\Enums;

enum MediaServerStatus:string
{
    // 'running','stopped','unknown','offline'
    case RUNNING = 'running';
    case STOPPED = 'stopped';
    case UNKNOWN = 'unknown';
    case OFFLINE = 'offline';
}
