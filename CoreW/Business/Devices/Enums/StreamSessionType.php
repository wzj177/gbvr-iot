<?php

namespace CoreW\Business\Devices\Enums;

enum StreamSessionType:string
{
    //'live','playback','download','talk'
    case LIVE = 'live';
    case PLAYBACK = 'playback';
    case DOWNLOAD = 'download';
    case TALK = 'talk';
}