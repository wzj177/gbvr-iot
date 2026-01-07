<?php

use app\admin\controller\AttachmentController;
use app\admin\controller\AttachmentGroupController;
use app\admin\controller\AuthController;
use app\admin\controller\GB28181AlarmController;
use app\admin\controller\GB28181ChannelController;
use app\admin\controller\GB28181DeviceController;
use app\admin\controller\GB28181MapController;
use app\admin\controller\GB28181PTZController;
use app\admin\controller\GB28181RecordingController;
use app\admin\controller\GB28181StreamController;
use app\admin\controller\GB28181SystemMonitoringController;
use app\admin\controller\ProductCatalogController;
use app\admin\controller\ProductTagController;
use app\admin\controller\SettingController;
use app\admin\controller\SystemController;
use app\admin\controller\SystemMonitoringController;
use app\admin\controller\UserController;
use app\admin\controller\MediaServerController;
use app\admin\controller\MenuController;
use app\admin\controller\RoleController;
use app\middleware\admin\AuthIdentityMiddleware;
use app\middleware\admin\PermissionCheckMiddleware;
use CoreW\CustomRoute as Route;
use function Swoole\Coroutine\Http\post;

// Include GB28181 routes
$gb28181RoutesFile = __DIR__ . '/gb28181.php';
if (file_exists($gb28181RoutesFile)) {
    include_once $gb28181RoutesFile;
}

Route::group('/api/admin', function () {
    // 登录认证
    Route::group('/auth', function () {
        Route::get('/config', [SettingController::class, 'getSecure'])->name('admin.login_config');
        Route::post('/login', [AuthController::class, 'login'])->name('admin.login');
        Route::get('/captcha', [AuthController::class, 'captcha'])->name('admin.captcha');
        Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout')->middleware([
            AuthIdentityMiddleware::class
        ]);
    });

    // 系统
    Route::group('/system', function () {
        Route::get('/logs', [SystemController::class, 'logs'])->name('system.logs');
        Route::get('/log/{id:\d+}', [SystemController::class, 'log'])->name('system.log');
        Route::post('/log/btch-dlt', [SystemController::class, 'batchDelete'])->name('system.log.batch-delete');
        Route::post('/cache-clear', [SystemController::class, 'clearCache'])->name('system.cache-clear');
        Route::post('/test-mail', [SystemController::class, 'testMail'])->name('system.test-mail');
        Route::get('/log/modules', [SystemController::class, 'logModuleOptions'])->name('system.log.module-options');
        Route::get('/log/actions/{module:\w+}', [SystemController::class, 'logActionOptions'])->name('system.log.action-options');

        // 系统监控相关接口
        Route::get('/stats', [SystemMonitoringController::class, 'getSystemStats'])->name('system.stats');

        // 详细监控指标接口
        Route::get('/cpu-usage', [SystemMonitoringController::class, 'getCpuUsage'])->name('system.cpu-usage');
        Route::get('/memory-usage', [SystemMonitoringController::class, 'getMemoryUsage'])->name('system.memory-usage');
        Route::get('/network-stats', [SystemMonitoringController::class, 'getNetworkStats'])->name('system.network-stats');
        Route::get('/disk-stats', [SystemMonitoringController::class, 'getDiskStats'])->name('system.disk-stats');
    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);

    // 菜单
    Route::group('/menu', function () {
        Route::get('', [MenuController::class, 'index'])->name('admin.menu.index');
        Route::get('/{id:\d+}', [MenuController::class, 'show'])->name('admin.menu.show');
        Route::post('', [MenuController::class, 'store'])->name('admin.menu.store');
        Route::put('/{id:\d+}', [MenuController::class, 'update'])->name('admin.menu.update');
        Route::delete('/{id:\d+}', [MenuController::class, 'destroy'])->name('admin.menu.destroy');
        Route::get('/tree', [MenuController::class, 'tree'])->name('admin.menu.tree');
        Route::post('/sync', [MenuController::class, 'sync'])->name('admin.menu.sync');
        Route::get('/user/menu', [MenuController::class, 'userMenu'])->name('admin.menu.user');
        Route::post('/batch-delete', [MenuController::class, 'batchDelete'])->name('admin.menu.batch-delete');
        Route::get('/type-options', [MenuController::class, 'typeOptions'])->name('admin.menu.type-options');
    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);

    // 管理员
    Route::group('/user', function () {
        Route::get('', [UserController::class, 'index'])->name('admin.user.index');
        Route::get('/{id:\d+}', [UserController::class, 'show'])->name('admin.user.show');
        Route::post('', [UserController::class, 'store'])->name('admin.user.store');
        Route::put('/{id:\d+}', [UserController::class, 'update'])->name('admin.user.update');
        Route::delete('/{id:\d+}', [UserController::class, 'destroy'])->name('admin.user.destroy');
        Route::post('/{id:\d+}/roles', [UserController::class, 'assignRoles'])->name('admin.user.assign-roles');
        Route::post('/{id:\d+}/reset-password', [UserController::class, 'resetPassword'])->name('admin.user.reset-password');
        Route::post('/{id:\d+}/toggle-lock', [UserController::class, 'toggleLock'])->name('admin.user.toggle-lock');
        Route::post('/batch-delete', [UserController::class, 'batchDelete'])->name('admin.user.batch-delete');
        Route::get('/role-options', [UserController::class, 'roleOptions'])->name('admin.user.role-options');
//        Route::get('/menus', [UserController::class, 'getMenuAdmin'])->name('user.menus');
    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);

    // 角色权限
    Route::group('/role', function () {
        Route::get('', [RoleController::class, 'index'])->name('admin.role.index');
        Route::get('/{id:\d+}', [RoleController::class, 'show'])->name('admin.role.show');
        Route::post('', [RoleController::class, 'store'])->name('admin.role.store');
        Route::put('/{id:\d+}', [RoleController::class, 'update'])->name('admin.role.update');
        Route::delete('/{id:\d+}', [RoleController::class, 'destroy'])->name('admin.role.destroy');
        Route::get('/{id:\d+}/menus', [RoleController::class, 'menus'])->name('admin.role.menus');
        Route::post('/{id:\d+}/menus', [RoleController::class, 'assignMenus'])->name('admin.role.assign-menus');
        Route::post('/batch-delete', [RoleController::class, 'batchDelete'])->name('admin.role.batch-delete');
        Route::get('/options', [RoleController::class, 'options'])->name('admin.role.options');
    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);
    // 会员
    Route::group('/vip', function () {

    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class
    ]);

    // 作品
    Route::group('/product', function () {
        Route::get('/catalog/tree', [ProductCatalogController::class, 'tree'])->name('product.catalog-tree');

        Route::resource('/catalog', ProductCatalogController::class, [
            'index', 'store', 'show', 'update', 'destroy'
        ], 'product-catalog');
        Route::post('/catalog/upd-sort/{id}', [ProductCatalogController::class, 'updateSort'])->name('product-catalog.upd-sort');
        Route::post('/catalog/upd-status/{id}', [ProductCatalogController::class, 'updateStatus'])->name('product-catalog.upd-status');
//        Route::post('/catalog/batch-delete', [ProductCatalogController::class, 'batchDestroy'])->name('product-catalog.batch-delete');
        Route::post('/tag/add', [ProductTagController::class, 'addTags'])->name('product.tag-add');
        Route::get('/tags', [ProductTagController::class, 'index'])->name('product.tags');
        Route::get('/tag/options', [ProductTagController::class, 'tagOptions'])->name('product.tag-options');
        Route::delete('/tag/{id}', [ProductTagController::class, 'destroy'])->name('product.tag-remove');
        Route::post('/tag/batch-delete', [ProductTagController::class, 'batchDestroy'])->name('product.tag-batch-delete');
    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);


    // 附件管理
    Route::group('/attachment', function () {
        Route::get('/group/trees', [AttachmentGroupController::class, 'trees'])->name('attachment-group.trees');
        Route::resource('/group', AttachmentGroupController::class, [
            'index', 'store', 'show', 'update', 'destroy'
        ], 'attachment-group');
        Route::post('/group/removes', [AttachmentGroupController::class, 'destroyMore'])->name('attachment-group.removes');
        Route::post('/upload', [AttachmentController::class, 'uploadOne'])->name('attachment.upload');
        Route::post('/uploads', [AttachmentController::class, 'uploadMulti'])->name('attachment.uploads');
        Route::post('/upload/base64-img', [AttachmentController::class, 'uploadBase64Image'])->name('attachment.upload-base64-img');
        Route::post('/upload/remote-file', [AttachmentController::class, 'uploadRemoteFile'])->name('attachment.upload-remote-file');
        Route::get('/snippet/check/{hash}', [AttachmentController::class, 'checkSnippet'])->name('attachment.snippet.check');
        Route::post('/snippet/upload', [AttachmentController::class, 'uploadSnippet'])->name('attachment.snippet.upload');
        Route::post('/snippet/merge', [AttachmentController::class, 'mergeSnippetFile'])->name('attachment.snippet.merge');
        Route::get('/index', [AttachmentController::class, 'index'])->name('attachment.list');
        Route::get('/view/{id}', [AttachmentController::class, 'show'])->name('attachment.view');
        Route::get('/download/{id}', [AttachmentController::class, 'download'])->name('attachment.download');
        Route::get('/config', [AttachmentController::class, 'config'])->name('attachment.config');
        Route::get('/type-options', [AttachmentController::class, 'typeOptions'])->name('attachment.type-options');
        Route::post('/move-group', [AttachmentController::class, 'moveGroup'])->name('attachment.map-group');
        Route::delete('/{id}', [AttachmentController::class, 'delete'])->name('attachment.delete');
        Route::post('/deletes', [AttachmentController::class, 'deletes'])->name('attachment.deletes');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);

    // 系统设置
    Route::group('/setting', function () {
        Route::get('/view/{key}', [SettingController::class, 'view'])->name('setting.get');
        Route::post('/basic', [SettingController::class, 'setBasic'])->name('setting.basic');
        Route::post('/auth', [SettingController::class, 'setAuth'])->name('setting.auth');
        Route::post('/attachment', [SettingController::class, 'setAttachment'])->name('setting.attachment');
        Route::post('/storage', [SettingController::class, 'setStorage'])->name('setting.storage');
        Route::post('/cdn', [SettingController::class, 'setCDN'])->name('setting.cdn');
        Route::post('/mail', [SettingController::class, 'setMail'])->name('setting.mail');
        Route::post('/ip-check-list', [SettingController::class, 'setIPCheckList'])->name('setting.ip-check-list');
        Route::post('/vip', [SettingController::class, 'setVIP'])->name('setting.vip');
        Route::get('/attachment/options', [SettingController::class, 'attachmentOptions'])->name('setting.attachment.options');
        Route::post('/vr', [SettingController::class, 'setVR'])->name('setting.vr');
    })->middleware([
//        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);


    Route::group('/gb28181', function () {
        // 设备管理
        Route::group('/devices', function () {
            Route::get('', [GB28181DeviceController::class, 'index'])->name('admin.gb28181.devices.index');
            Route::get('/tree', [GB28181DeviceController::class, 'tree'])->name('admin.gb28181.devices.tree');
            Route::get('/{id}', [GB28181DeviceController::class, 'show'])->name('admin.gb28181.devices.show');
            Route::put('/{id}', [GB28181DeviceController::class, 'update'])->name('admin.gb28181.devices.update');
            Route::delete('/{id}', [GB28181DeviceController::class, 'destroy'])->name('admin.gb28181.devices.destroy');
            Route::post('/{id}/catalog', [GB28181DeviceController::class, 'queryCatalog'])->name('admin.gb28181.devices.query-catalog');
            Route::put('/batch/area', [GB28181DeviceController::class, 'batchUpdateArea'])->name('admin.gb28181.devices.batch-area');
        });

        // 通道管理
        Route::group('/channels', function () {
            Route::get('', [GB28181ChannelController::class, 'index'])->name('admin.gb28181.channels.index');
            Route::get('/{id}', [GB28181ChannelController::class, 'show'])->name('admin.gb28181.channels.show');
            Route::put('/{id}', [GB28181ChannelController::class, 'update'])->name('admin.gb28181.channels.update');
            Route::put('/batch/bind-media', [GB28181ChannelController::class, 'batchBindMedia'])->name('admin.gb28181.channels.batch-bind-media');
        });

        // PTZ控制
        Route::group('/ptz', function () {
            Route::post('', [GB28181PTZController::class, 'control'])->name('admin.gb28181.ptz.control');
        });

        // 视频流管理
        Route::group('/streams', function () {
            Route::post('/start-live', [GB28181StreamController::class, 'startLive'])->name('admin.gb28181.streams.start-live');
            Route::post('/stop-live', [GB28181StreamController::class, 'stopLive'])->name('admin.gb28181.streams.stop-live');
            Route::get('/play-urls', [GB28181StreamController::class, 'getPlayUrls'])->name('admin.gb28181.streams.play-urls');
            Route::post('/playback', [GB28181StreamController::class, 'startPlayback'])->name('admin.gb28181.streams.start-playback');
        });

        // 录像管理
        Route::group('/recordings', function () {
            Route::get('', [GB28181RecordingController::class, 'index'])->name('admin.gb28181.recordings.index');
            Route::post('/start-record', [GB28181RecordingController::class, 'startRecord'])->name('admin.gb28181.recordings.start-record');
            Route::post('/stop-record', [GB28181RecordingController::class, 'stopRecord'])->name('admin.gb28181.recordings.stop-record');
            Route::post('/snapshot', [GB28181RecordingController::class, 'snapshot'])->name('admin.gb28181.recordings.snapshot');
        });

        // 报警管理
        Route::group('/alarms', function () {
            Route::get('', [GB28181AlarmController::class, 'index'])->name('admin.gb28181.alarms.index');
            Route::get('/{id}', [GB28181AlarmController::class, 'show'])->name('admin.gb28181.alarms.show');
            Route::put('/{id}', [GB28181AlarmController::class, 'update'])->name('admin.gb28181.alarms.update');
        });

        // 电子地图
        Route::group('/map', function () {
            Route::get('/devices', [GB28181MapController::class, 'getDevices'])->name('admin.gb28181.map.devices');
            Route::put('/devices/{id:\w+}/position', [GB28181MapController::class, 'updatePosition'])->name('admin.gb28181.map.update-position');
        });

        // 系统监控
        Route::get('/device-stats', [GB28181SystemMonitoringController::class, 'getDeviceStats'])->name('admin.gb28181.system.device-stats');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);

    Route::group('/media-server', function () {
        // TODO: 需要实现
        Route::get('',  [MediaServerController::class, 'index'])->name( 'media-server.index');
        Route::post('/{id}/restart', [MediaServerController::class, 'restart'])->name('media-server.restart');
        Route::get('/{id}/stats', [MediaServerController::class, 'getZLMediaKitStats'])->name('media-server.stats');
        Route::post('/add', [MediaServerController::class, 'store'])->name('media-server.store');
        Route::post('/{id}/config', [MediaServerController::class, 'setConfig'])->name('media-server.set-config');
        Route::get('/{id}/config', [MediaServerController::class, 'getConfig'])->name('media-server.get-config');
        Route::put('/{id}', [MediaServerController::class, 'update'])->name('media-server.update');
        Route::delete('/{id}', [MediaServerController::class, 'delete'])->name('media-server.delete');
        Route::get('/{id}', [MediaServerController::class, 'show'])->name('media-server.show');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class
    ]);
});