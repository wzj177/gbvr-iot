<?php

namespace CoreW\Business\MediaServer\Enums;

enum ServerStatusEnum: string
{
    case RUNNING = 'running';
    case STOPPED = 'stopped';
    case UNKNOWN = 'unknown';
}
