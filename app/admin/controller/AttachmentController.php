<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\AttachmentFilter;
use CoreW\Business\Attachment\Dto\FileUploadDto;
use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Business\BizEnum;
use support\Request;
use Respect\Validation\Validator as v;
use support\utils\ArrayToolkit;
use support\utils\Paginator;
use support\utils\StringToolkit;

class AttachmentController extends BaseController
{
    public function delete(Request $request, $id)
    {
        if ($this->getAttachmentService()->deleteAttachmentById($id)) {
            return $this->createSuccessJsonResponse('删除成功');
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    public function deletes(Request $request)
    {
        $ids = $request->post('ids', []);

        $this->getAttachmentService()->deleteFilesByIds($ids);

        return $this->createSuccessJsonResponse('删除成功');
    }

    public function moveGroup(Request $request)
    {
        $ids = $request->post('ids', []);
        $groupCode = $request->post('groupCode', null);

        $result = $this->getAttachmentService()->moveGroup($ids, $groupCode);
        if ($result) {
            return $this->createSuccessJsonResponse(null, '分组移动成功');
        }

        return $this->createErrorJsonResponse('分组移动失败');
    }

    public function typeOptions(Request $request)
    {
        return $this->createSuccessJsonResponse(ArrayToolkit::enumToList(BizEnum::getFileTypeItems()));
    }

    public function index(Request $request)
    {
        $conditions = [];
        $fields = $request->get();
        if (!empty($fields['keyword'])) {
            $conditions['keyword'] = $fields['keyword'];
        }
        if (!empty($fields['type'])) {
            $conditions['type'] = $fields['type'];
        }
        if (!empty($fields['status'])) {
            $conditions['status'] = $fields['status'];
        }
        if (!empty($fields['storage'])) {
            $conditions['storage'] = $fields['storage'];
        }
        if (!empty($fields['Client'])) {
            $conditions['createClient'] = $fields['Client'];
        }
        if (!empty($fields['group'])) {
            $conditions['group'] = $fields['group'];
        }

        if (!empty($fields['start_time']) && StringToolkit::is_valid_date($fields['start_time'], 'Y-m-d H:i')) {
            $conditions['startTime'] = strtotime($fields['start_time']);
        }

        if (!empty($fields['end_time']) && StringToolkit::is_valid_date($fields['end_time'], 'Y-m-d H:i')) {
            $conditions['endTime'] = strtotime($fields['end_time']);
        }

        $total = $this->getAttachmentService()->countAttachments($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);
        $sort = $this->getSort($request);
        $sort['id'] = 'DESC';
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);
        $files = $this->getAttachmentService()->searchAttachments($conditions, $sort, $paginator->getOffsetCount(), $paginator->getPerPageCount());
        $filter = new AttachmentFilter();
        $filter->filters($files);

        return $this->createSuccessJsonResponse([
            'list'      => $files,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    public function show(Request $request, $id)
    {
        $file = $this->getAttachmentService()->getAttachmentById($id);
        $filter = new AttachmentFilter();
        $filter->filter($file);

        return $this->createSuccessJsonResponse($file);
    }

    public function download(Request $request, $id)
    {
        $file = $this->getAttachmentService()->getAttachmentById($id);

        return response()->download(uploads_path() . '/' . ltrim($file['filepath'], 'uploads'), $file['filename']);
    }

    public function config(Request $request)
    {
        $config = $this->getSettingService()->get('attachment', []);
        $config['max_package_size'] = \config('server.max_package_size');

        return $this->createSuccessJsonResponse($config);
    }

    public function view(Request $request, $id)
    {
        return response(__METHOD__ . __FUNCTION__);
    }

    /**
     * 查询分片文件是否上传
     *
     * @param Request $request
     * @param $hash
     * @return \support\Response
     */
    public function checkSnippet(Request $request, $hash)
    {
        $chunkFiles = $this->getAttachmentService()->getChunkFilesByHashID($hash);

        return $this->createSuccessJsonResponse($chunkFiles);
    }

    /**
     * 上传切片文件
     * TODO：方案二：记录切片索引和切片大小（第一次记录总文件大小），每次上传就写入到tmp文件，当索引为最后一个切片数-1 同时临时文件的大小==原文件大小就表示上传完成或者用hash_file
     *
     * @param Request $request
     * @return \support\Response
     */
    public function uploadSnippet(Request $request)
    {
        $fields = v::input($request->post(), [
            'index'    => v::intVal()->setName('切片索引必须是正整数'),
            'hash'     => v::notEmpty()->setName('分片hashID必须存在'),
            'filename' => v::notEmpty()->setName('源文件名称名必须存在'),
        ]);
        $index = $fields['index'];
        $hash = $fields['hash'];
        $name = $fields['filename'];
        $file = $request->file('file');
        $result = $this->getAttachmentService()->uploadSnippet($file, $hash, $index, $name);

        if ($result) {
            return $this->createSuccessJsonResponse(null, '切片上传完成');
        }

        return $this->createErrorJsonResponse('切片上传失败');
    }

    /**
     * 文件合并接口
     *
     * 1、判断是否存在hash文件夹
     * 2、判断文件夹内的文件数量是否等于总切片数
     * 3、合并文件
     * 4、清空切片文件
     * @param Request $request
     */
    public function mergeSnippetFile(Request $request)
    {
        $fields = v::input($request->post(), [
            'name'  => v::notEmpty()->setTemplate("上传文件名必须存在"),
            'size'  => v::numericVal()->setTemplate("文件大小必须是数字"),
            'total' => v::callback(function ($value) {
                return intval($value) > 0;
            })->setTemplate("切片总数参数异常"),
            'hash'  => v::notEmpty()->setName('分片hashID必须存在'),
        ]);
        $fields['create_user_id'] = $this->getCurrentUser()->getId();
        $fields['group'] = $request->post('group', 'default');
        $fields['Client'] = 'backend';

        $result = $this->getAttachmentService()->mergeSnippetFile($fields);

        return $this->createSuccessJsonResponse($result);
    }

    /**
     * 上传单文件
     *
     * @param Request $request
     * @return \support\Response
     */
    public function uploadOne(Request $request)
    {
        $key = $request->post('key', 'file');
        $group = $request->post('group', 'default');
        $file = $request->file($key);
        $dto = new FileUploadDto([
            'file'   => $file,
            'type'   => 'single_file_upload',
            'group'  => $group,
            'userId' => $this->getCurrentUser()->getId(),
            'client' => 'backend',
        ]);
        $result = $this->getAttachmentService()->uploadFile($dto);

        return $this->createSuccessJsonResponse($result);
    }

    /**
     * 上传base64 图片文件
     *
     * @param Request $request
     * @return \support\Response
     */
    public function uploadBase64Image(Request $request)
    {
        $base64Str = $request->post('base64_str', '');
        $group = $request->post('group', 'default');
        $dto = new FileUploadDto([
            'base64Str' => $base64Str,
            'type'      => 'base64_upload',
            'group'     => $group,
            'userId'    => $this->getCurrentUser()->getId(),
            'client'    => 'backend',
        ]);
        $result = $this->getAttachmentService()->uploadBase64Image($dto);

        return $this->createSuccessJsonResponse($result);

    }

    /**
     * 上传网络文件（图片、视频、音频等）
     *
     * @param Request $request
     * @return \support\Response
     */
    public function uploadRemoteFile(Request $request)
    {
        $url = $request->post('url', '');
        $group = $request->post('group', 'default');
        $dto = new FileUploadDto([
            'url'    => $url,
            'type'   => 'remote_file_upload',
            'group'  => $group,
            'userId' => $this->getCurrentUser()->getId(),
            'client' => 'backend',
        ]);
        $result = $this->getAttachmentService()->uploadRemoteFile($dto);

        return $this->createSuccessJsonResponse($result);

    }

    /**
     * 多文件上传
     * 支持 前端：file[] => [] 或者 file1 => {} file2 => {}
     * @param Request $request
     * @return \support\Response
     */
    public function uploadMulti(Request $request)
    {
        $key = $request->post('key', null);
        $group = $request->post('group', 'default');
        if (!empty($key)) {
            $files = $request->file($key);
            if (count($files) == 1) {
                $files = [$files];
            }
        } else {
            $files = $request->file();
        }
        $dto = new FileUploadDto([
            'files'  => $files,
            'type'   => 'multi_file_upload',
            'group'  => $group,
            'userId' => $this->getCurrentUser()->getId(),
            'client' => 'backend',
        ]);
        if ($this->getAttachmentService()->uploadFiles($dto)) {
            return $this->createSuccessJsonResponse();
        }

        return $this->createErrorJsonResponse("上传失败了");
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->createService('Attachment:AttachmentService');
    }
}
