<?php

use app\api\v1\controller\AuthController;
use app\api\v1\controller\IotController;
use app\api\v1\controller\ProductController;
use app\api\v1\controller\VIPController;
use app\api\v1\controller\PublicController;
use app\middleware\api\CompanyIotMiddleware;
use app\middleware\api\XAuthTokenIdentityMiddleware;
use app\middleware\ProductAnonymousVisitMiddleware;
use support\Request;
use Webman\Route;

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
            XAuthTokenIdentityMiddleware::class
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
            XAuthTokenIdentityMiddleware::class
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
            XAuthTokenIdentityMiddleware::class
        ]);

        // 物联网
        Route::group('/iot', function() {
            Route::get('/device/catalogs', [IotController::class, 'getDeviceCatalogs'])->name('iot.device.catalogs');
           Route::get('/device/list', [IotController::class, 'getDeviceList'])->name('iot.device.list');
        })->middleware([
            XAuthTokenIdentityMiddleware::class,
            CompanyIotMiddleware::class
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
            XAuthTokenIdentityMiddleware::class
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
            Route::post('/server/hock', [\app\api\v2\controller\GBServerHockController::class, 'index'])->middleware([
                \app\middleware\GBHock::class
            ]);
            Route::get('/devices/pull', [\app\api\v2\controller\GB28181DeviceController::class, 'pullOnLineList']);
        });
        
        // GB28181 设备管理
        Route::group('/gb28181', function () {
            // 设备列表
            Route::get('/devices', [\app\api\v2\controller\GB28181DeviceController::class, 'index']);
            // 设备详情
            Route::get('/devices/{id}', [\app\api\v2\controller\GB28181DeviceController::class, 'show']);
            // 设备通道列表
            Route::get('/devices/{id}/channels', [\app\api\v2\controller\GB28181DeviceController::class, 'channels']);
            // 查询设备目录（主动向设备发起Catalog查询）
            Route::post('/devices/{id}/catalog', [\app\api\v2\controller\GB28181DeviceController::class, 'queryCatalog']);
            // 删除设备
            Route::delete('/devices/{id}', [\app\api\v2\controller\GB28181DeviceController::class, 'destroy']);
            
            // 流控制
            Route::post('/channels/start-live', [\app\api\v2\controller\GB28181StreamController::class, 'startLive']);
            Route::post('/channels/stop-live', [\app\api\v2\controller\GB28181StreamController::class, 'stopLive']);
            Route::get('/channels/play-urls', [\app\api\v2\controller\GB28181StreamController::class, 'getPlayUrls']);
            Route::post('/channels/playback', [\app\api\v2\controller\GB28181StreamController::class, 'startPlayback']);
            Route::post('/channels/ptz', [\app\api\v2\controller\GB28181StreamController::class, 'ptzControl']);
        })->middleware([
            XAuthTokenIdentityMiddleware::class
        ]);
    });
});