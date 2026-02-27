<?php

use app\admin\controller\SystemMonitoringController;
use app\api\v1\controller\AuthController;
use app\api\v1\controller\IotController;
use app\api\v1\controller\ProductController;
use app\api\v1\controller\VIPController;
use app\api\v1\controller\PublicController;
use app\middleware\api\AuthIdentityMiddleware;
use app\middleware\admin\AuthIdentityMiddleware as AdminAuthIdentityMiddleware;
use app\middleware\ProductAnonymousVisitMiddleware;
use support\Request;
use Webman\Route;

// 对外开放 openapi 接口
Route::group('/api', function () {
    Route::group('/v1', function () {
        // 登录、注册
        Route::group('/auth', function () {
            Route::get('/index', [AuthController::class, 'index'])->name('api.auth');
            Route::post('/register', [AuthController::class, 'register'])->name('api.register');
            Route::post('/login', [AuthController::class, 'login'])->name('api.login');
            Route::post('/email-login', [AuthController::class, 'emailLogin'])->name('api.email-login');
//            Route::get('/captcha', [AuthController::class, 'captcha'])->name('api.captcha');
            Route::post('/send-email-code', [AuthController::class, 'sendEmailLoginCode'])->name('api.send-login-email-code');
            Route::get('/config', [AuthController::class, 'config'])->name('api.auth-config');
            Route::post('/oauth2-qq-url', [AuthController::class, 'qqAuthUrl'])->name('api.auth-oauth2-qq-auth-url');
            Route::post('/qq-login', [AuthController::class, 'qqLogin'])->name('api.qq-login');
        });

        Route::post('/iot/sso/login', [IotController::class, 'auth'])->name('iot.auth');


        // 退出
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout')->middleware([
            AuthIdentityMiddleware::class
        ]);

        // 会员中心
        Route::group('/vip', function () {
            Route::get('/company/iot/apis', [VIPController::class, 'companyIotApiList'])->name('api.vip-company-iot-apis');
            Route::get('/company/iot/gateways', [VIPController::class, 'companyIotServiceList'])->name('api.vip-company-iot-gateway-list');
            Route::get('/company/{uid}', [VIPController::class, 'getCompany'])->name('api.vip-company-info');
            Route::post('/company/apply', [VIPController::class, 'applyCompany'])->name('api.vip-company-apply');
            Route::get('/company/iot-config/{id}', [VIPController::class, 'getCompanyIotConfig'])->name('api.vip-company-iot-config-get');
            Route::post('/company/iot-config/{id}', [VIPController::class, 'companyIotConfig'])->name('api.vip-company-iot-config-set');
            Route::post('/send-email-verify', [VIPController::class, 'sendEmailVerify'])->name('api.send-email-verify');
            Route::get('/me', [VIPController::class, 'info'])->name('api.vip.info');
            Route::put('/edit/{id}', [VIPController::class, 'edit'])->name('api.vip.edit');
            Route::get('/products', [ProductController::class, 'myList'])->name('api.vip.products');
        })->middleware([
            AuthIdentityMiddleware::class
        ]);

        // 作品
        Route::group('/product', function () {
            Route::get('/types', [ProductController::class, 'typeList'])->name('api.product-type-list');
            Route::get('/catalogs', [ProductController::class, 'catalogList'])->name('api.product-catalog-list');
            Route::get('/tags', [ProductController::class, 'tagList'])->name('api.product-tag-list');
            Route::get('/scene-upload-config', [ProductController::class, 'sceneUploadConfig'])->name('api.product-scene-upload-config');
            Route::post('/scene/upload', [ProductController::class, 'sceneUpload'])->name('api.product-scene-upload');
            Route::get('/hotpoint/type-list', [ProductController::class, 'getHotpointTypeItems'])->name('api.product-hotpoint-type-list');
            Route::post('/create', [ProductController::class, 'createProduct'])->name('api.product-create');
            Route::get('/view/{id}', [ProductController::class, 'getProductViewInfo'])->name('api.product-view');
            Route::put('/{id:\d+}', [ProductController::class, 'updateProduct'])->name('api.product-update');
            Route::put('/like/{id:\d+}', [ProductController::class, 'likeProduct'])->name('api.product-like');
            Route::get('/{id:\d+}', [ProductController::class, 'getProduct'])->name('api.product-info');
            Route::delete('/{id:\d+}', [ProductController::class, 'deleteProduct'])->name('api.product-delete');
            Route::put('/close/{id:\d+}', [ProductController::class, 'closeProduct'])->name('api.product-close');
            Route::put('/publish/{id:\d+}', [ProductController::class, 'publishProduct'])->name('api.product-publish');
            Route::post('/scene/add/{id:\d+}', [ProductController::class, 'addScene'])->name('api.product-scene-add');
            Route::post('/scene/sort/{id:\d+}', [ProductController::class, 'sortScene'])->name('api.product-scene-update-sort');
            Route::put('/scene/{id:\d+}', [ProductController::class, 'updateScene'])->name('api.product-scene-update');
            Route::delete('/scene/{id:\d+}', [ProductController::class, 'removeScene'])->name('api.product-scene-delete');
            Route::post('/hotpoint/make', [ProductController::class, 'addHotPoint'])->name('api.product-make-hotpoint');
            Route::get('/hotpoint/{uuid}', [ProductController::class, 'getHotPoint'])->name('api.product-get-hotpoint');
            Route::delete('/hotpoint/{id:\d+}', [ProductController::class, 'delHotPoint'])->name('api.product-del-hotpoint');
            Route::delete('/scene/hots/{id:\d+}', [ProductController::class, 'delSceneHotPoints'])->name('api.product-del-scene-hot-points');
            Route::post('/tour/set/{id:\d+}', [ProductController::class, 'tourGlobalSet'])->name('api.product-tour-set');
            Route::get('/tour/{id:\d+}', [ProductController::class, 'getProductTour'])->name('api.product-tour-info');
            Route::get('/tour/nodes/{id:\d+}', [ProductController::class, 'getTourNodes'])->name('api.product-tour-nodes');
            Route::delete('/tour/node/{id:\d+}', [ProductController::class, 'delProductTour'])->name('api.product-tour-delete');
            Route::post('/tour/nodes/{id:\d+}', [ProductController::class, 'tourNodesSet'])->name('api.product-tour-add-nodes');
            Route::post('/plane/{id:\d+}', [ProductController::class, 'createPlaneGraphMarkers'])->name('api.product-create-plane-graph-markers');
            Route::put('/logo/{id:\d+}', [ProductController::class, 'setLogo'])->name('api.product-set-logo');
            Route::get('/logo/{id:\d+}', [ProductController::class, 'getLogo'])->name('api.product-get-logo');
            Route::put('/config/{id:\d+}', [ProductController::class, 'setConfig'])->name('api.product-set-config');
            Route::get('/config/{id:\d+}/{key:\w+}', [ProductController::class, 'getConfig'])->name('api.product-get-config');
        })->middleware([
            AuthIdentityMiddleware::class
        ]);

        // 物联网
        Route::group('/iot', function() {
            Route::get('/device/catalogs', [IotController::class, 'getDeviceCatalogs'])->name('iot.device.catalogs');
           Route::get('/device/list', [IotController::class, 'getDeviceList'])->name('iot.device.list');
        })->middleware([
//            XAuthTokenIdentityMiddleware::class,
//            CompanyIotMiddleware::class
        ]);

        // 作品:无需认证,游客也可访问
        Route::group('', function () {
            Route::get('/product/vr/{id}', [ProductController::class, 'getProductViewInfo'])->name('api.product-vr');
            Route::get('/product/scene/{id:\d+}', [ProductController::class, 'showScene'])->name('api.product-scene-info');
            Route::get('/product/scenes', [ProductController::class, 'sceneList'])->name('api.product-scene-list');
            Route::get('/product/scene/hots/{id:\d+}', [ProductController::class, 'getSceneHotPoints'])->name('api.product-scene-hotpoint-list');
            Route::post('/product/check-pwd', [ProductController::class, 'validateViewPassword'])->name('api.product-validate-view-password');
            Route::get('/product/share/{id:\d+}', [ProductController::class, 'makeShareUrl'])->name('api.product-make-share-url');
            Route::post('/product/share/check', [ProductController::class, 'checkShareToken'])->name('api.product-check-share-token');
            Route::get('/product/plane/{id:\d+}', [ProductController::class, 'getProductPlaneGraph'])->name('api.product-get-plane-graph-markers');
            Route::get('/iot/device/info/{deviceCode}', [IotController::class, 'getDeviceInfo'])->name('iot.device.info');
            Route::get('/iot/device/real-data/{deviceCode}', [IotController::class, 'getDeviceRealData'])->name('iot.device.real-data');
            Route::get('/iot/device/history-data/{deviceCode}', [IotController::class, 'getDeviceHistoryData'])->name('iot.device.history-data');
            Route::get('/iot/camera/live-url/{deviceCode}', [IotController::class, 'getCameraLiveUrl'])->name('iot.camera.live-url');
            Route::get('/iot/gis/tiles-url', [IotController::class, 'getGisTilesUrl'])->name('iot.gis.tiles-url');
        })->middleware([
            ProductAnonymousVisitMiddleware::class
        ]);

        // 上传
        Route::post('/upload/file', [\app\api\v1\controller\UploadController::class, 'singleFile'])->name('api.common.upload.file')->middleware([
            AuthIdentityMiddleware::class
        ]);

        // 邮箱验证
        Route::get('/email-verify', [VIPController::class, 'emailVerify'])->name('api.vip.email-verify');
        Route::any('/index', function (Request $request) {
            return response('Welcome To ' . config('app.name') . ' Api V1');
        });

        Route::group('/public', function () {
            Route::get('/dict/{key}', [PublicController::class, 'getDictItems'])->name('api.public.dict-items');
        });
    });

    Route::group('/v2', function () {
        Route::group('/gb', function () {
            Route::post('/server/hook', [\app\api\v2\controller\GBServerHookController::class, 'index'])->middleware([
                \app\middleware\GBHook::class
            ]);
            Route::get('/devices/pull', [\app\api\v2\controller\GB28181DeviceController::class, 'pushOnLineList']);
        });

        Route::group('/zlm_hook', function () {

            // 流量报告事件
            Route::post('/on_flow_report', [\app\api\v2\controller\ZLMHookController::class, 'onFlowReport'])->name('api.zlm_hook.on_flow_report');

            // HTTP 访问鉴权事件
            Route::any('/on_http_access', [\app\api\v2\controller\ZLMHookController::class, 'onHttpAccess'])->name('api.zlm_hook.on_http_access');

            // 播放事件
            Route::post('/on_play', [\app\api\v2\controller\ZLMHookController::class, 'onPlay'])->name('api.zlm_hook.on_play');

            // 推流事件
            Route::post('/on_publish', [\app\api\v2\controller\ZLMHookController::class, 'onPublish'])->name('api.zlm_hook.on_publish');

            // MP4 录像完成事件
            Route::post('/on_record_mp4', [\app\api\v2\controller\ZLMHookController::class, 'onRecordMp4'])->name('api.zlm_hook.on_record_mp4');

            // HLS/TS 录像完成事件
            Route::post('/on_record_ts', [\app\api\v2\controller\ZLMHookController::class, 'onRecordTs'])->name('api.zlm_hook.on_record_ts');

            // RTSP 认证事件
            Route::post('/on_rtsp_auth', [\app\api\v2\controller\ZLMHookController::class, 'onRtspAuth'])->name('api.zlm_hook.on_rtsp_auth');

            // RTSP Realm 事件
            Route::any('/on_rtsp_realm', [\app\api\v2\controller\ZLMHookController::class, 'onRtspRealm'])->name('api.zlm_hook.on_rtsp_realm');

            // 服务器启动事件
            Route::post('/on_server_started', [\app\api\v2\controller\ZLMHookController::class, 'onServerStarted'])->name('api.zlm_hook.on_server_started');

            // on_server_keepalive
            Route::post('/on_server_keepalive', [\app\api\v2\controller\ZLMHookController::class, 'onServerKeepalive'])->name('api.zlm_hook.on_server_keepalive');

            // Shell 登录事件
            Route::post('/on_shell_login', [\app\api\v2\controller\ZLMHookController::class, 'onShellLogin'])->name('api.zlm_hook.on_shell_login');

            // 流注册/注销事件
            Route::post('/on_stream_changed', [\app\api\v2\controller\ZLMHookController::class, 'onStreamChanged'])->name('api.zlm_hook.on_stream_changed');

            // 流无人观看事件
            Route::post('/on_stream_none_reader', [\app\api\v2\controller\ZLMHookController::class, 'onStreamNoneReader'])->name('api.zlm_hook.on_stream_none_reader');

            // 流未找到事件
            Route::post('/on_stream_not_found', [\app\api\v2\controller\ZLMHookController::class, 'onStreamNotFound'])->name('api.zlm_hook.on_stream_not_found');

            // FFmpeg 拉流未找到事件
            Route::post('/on_stream_not_found_ffmpeg', [\app\api\v2\controller\ZLMHookController::class, 'onStreamNotFoundFfmpeg'])->name('api.zlm_hook.on_stream_not_found_ffmpeg');

            // RTP 超时事件
            Route::post('/on_rtp_server_timeout', [\app\api\v2\controller\ZLMHookController::class, 'onRtpServerTimeout'])->name('api.zlm_hook.on_rtp_server_timeout');
        })->middleware([
            \app\middleware\ZLMHook::class
        ]);
    });
});