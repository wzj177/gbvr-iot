<?php

namespace app\api\v1\controller;

use app\AbstractController;
use support\Request;
use support\Response;

class RecordMergeFileController extends AbstractController
{
    public function serveFile(Request $request, int $mediaServerId, string $filename): Response
    {
        // 安全检查：只允许字母数字和下划线、点
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.mp4$/', $filename)) {
            return $this->createErrorJsonResponse('无效的文件名', 400);
        }

        $filePath = storage_path('record_merge/' . $mediaServerId . '/' . $filename);

        if (!file_exists($filePath)) {
            return $this->createErrorJsonResponse('文件不存在', 404);
        }

        // 设置合适的 Content-Type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        // 返回文件流
        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
