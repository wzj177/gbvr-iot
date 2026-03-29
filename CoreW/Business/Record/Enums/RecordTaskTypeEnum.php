<?php

namespace CoreW\Business\Record\Enums;

enum RecordTaskTypeEnum:string
{
    case ALARM = 'alarm';

    case PLAYBACK_DOWNLOAD = 'playback_download';
}
