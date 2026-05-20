<?php

namespace CoreW\Business\Devices\Enums;

enum StreamSessionStatus: string
{
    // 'inviting','active','stopped','error'
    case Inviting = 'inviting';
    case Active = 'active';
    case Stopped = 'stopped';
    case Error = 'error';
}