<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use support\Log;
use support\Request;

class ZLMHookController extends BaseController
{
    /**
     * 流无人观看时事件，用户可以通过此事件选择是否关闭无人看的流。 一个直播流注册上线了，如果一直没人观看也会触发一次无人观看事件，触发时的协议schema是随机的，看哪种协议最晚注册(一般为hls)。 后续从有人观看转为无人观看，触发协议schema为最后一名观看者使用何种协议。 目前mp4/hls录制不当做观看人数(mp4录制可以通过配置文件mp4_as_player控制，但是rtsp/rtmp/rtp转推算观看人数，也会触发该事件。
     * @param Request $request
     * @return \support\Response
     */
    public function onStreamNoneReader(Request $request): \support\Response
    {
        try {
            $mediaServerId = $request->post('media_server_id');
            $streamId = $request->post('stream_id');
            $sessions = $this->getDeviceService()->searchSessions([
                'media_server_id' => $mediaServerId,
                'stream_id' => $streamId,
                'viewer_count_GE' => 1
            ], [], 0, PHP_INT_MAX, ['id']);
            $ids = array_column($sessions, 'id');
            $this->getDeviceService()->batchDeleteSessions($ids);
        } catch (\Throwable $e) {
            Log::channel('zlm_hook')->error("onStreamNoneReader clean session error:" . $e->getMessage());
        };

        return json(['code' => 0, 'close' => true]);
    }

    /**
     * @return Gb28181Service
     */
    protected function getGB28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }


    protected function getDeviceService(): DeviceService
    {
        return $this->getBiz()->service('Devices:DeviceService');
    }
}