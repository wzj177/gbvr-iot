<?php

namespace app\api\v1\controller;

use app\api\BaseController;
use CoreW\Business\Attachment\Dto\FileUploadDto;
use CoreW\Business\Attachment\Service\AttachmentService;
use support\Request;

class UploadController extends BaseController
{
    /**
     * 上传单文件
     *
     * @param Request $request
     * @return \support\Response
     */
    public function singleFile(Request $request)
    {
        $key = $request->post('key', 'file');
        $group = $request->post('group', 'default');
        $file = $request->file($key);
        $dto = new FileUploadDto([
            'file'   => $file,
            'type'   => 'single_file_upload',
            'group'  => $group,
            'userId' => $this->getVIPInfo()->getId(),
            'client' => 'frontend',
        ]);
        $result = $this->getAttachmentService()->uploadFile($dto);

        return $this->createSuccessJsonResponse($result);
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->createService('Attachment:AttachmentService');
    }
}