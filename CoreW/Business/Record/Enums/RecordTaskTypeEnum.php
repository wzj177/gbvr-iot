<?php

namespace CoreW\Business\Record\Enums;

enum RecordTaskTypeEnum:string
{
    case PLAN = 'plan';

    case ALARM = 'alarm';

    case PLAYBACK_DOWNLOAD = 'playback_download';
}
