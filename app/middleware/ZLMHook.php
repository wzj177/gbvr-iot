<?php

namespace app\middleware;

use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Core;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * ZLM Media Server Hook Middleware
 *
 * 验证 ZLM Hook 请求中的 mediaServerId 参数，确保请求来自已注册的媒体服务器
 */
class ZLMHook implements MiddlewareInterface
{
    /**
     * 不需要 mediaServerId 验证的白名单路由 /api/v2/zlm_hook/
     * 这些 hook 通常在服务器启动或特殊场景下触发，可能还没有 mediaServerId
     */
    private array $whiteRoutes = [
        'on_server_started',    // 服务器启动时触发
        'on_stream_changed',    // 流注册/注销时触发（可能由外部触发）
        'on_rtsp_realm',        // RTSP realm 查询
    ];

    public function process(Request $request, callable $next): Response
    {
//        Log::channel('zlm_hook')->debug('ZLM Hook Request: ' . $request->rawBody());
        $mediaServerId = $request->post('mediaServerId');

        // 如果 mediaServerId 为空，检查是否在白名单中
        if (empty($mediaServerId)) {
            $action = $this->extractHookAction($request->path());
            if (in_array($action, $this->whiteRoutes)) {
                return $next($request);
            }

            return response('mediaServerId is empty', 400);
        }

        /**@var MediaServerService $mediaServerService */
        $mediaServerService = Core::instance()->service('MediaServer:MediaServerService');
        $mediaServer = $mediaServerService->getMediaServerByServerId($mediaServerId);

        if (empty($mediaServer)) {
            return response('mediaServer not found', 404);
        }

        if ($mediaServer['type'] !== MediaServerType::ZLM->value) {
            return response('mediaServer type is not ZLM', 400);
        }

        // 验证通过，将 mediaServer 信息存入 request 供后续使用
        $request->mediaServer = $mediaServer;

        return $next($request);
    }

    /**
     * 从请求路径中提取 hook action
     */
    private function extractHookAction(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        return end($parts) ?: '';
    }
}