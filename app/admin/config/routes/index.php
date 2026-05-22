<?php

use app\admin\controller\AlarmPlanController;
use app\admin\controller\AttachmentController;
use app\admin\controller\AttachmentGroupController;
use app\admin\controller\AuthController;
use app\admin\controller\GB28181AlarmController;
use app\admin\controller\GB28181ChannelController;
use app\admin\controller\GB28181DeviceController;
use app\admin\controller\GB28181DeviceCategoryController;
use app\admin\controller\GB28181DeviceControlController;
use app\admin\controller\GB28181DevicePositionController;
use app\admin\controller\GB28181MapController;
use app\admin\controller\GB28181PTZController;
use app\admin\controller\GB28181RecordPlanController;
use app\admin\controller\GB28181RecordMergeController;
use app\admin\controller\GB28181RecordTaskController;
use app\admin\controller\GB28181RecordingController;
use app\admin\controller\GB28181StreamController;
use app\admin\controller\GB28181SystemMonitoringController;
use app\admin\controller\MediaServerController;
use app\admin\controller\MenuController;
use app\admin\controller\ProductCatalogController;
use app\admin\controller\ProductTagController;
use app\admin\controller\RoleController;
use app\admin\controller\SettingController;
use app\admin\controller\SystemController;
use app\admin\controller\SystemMonitoringController;
use app\admin\controller\UserController;
use app\middleware\admin\AuthIdentityMiddleware;
use app\middleware\admin\PermissionCheckMiddleware;
use CoreW\CustomRoute as Route;
use app\admin\controller\StreamProxyController;
use app\admin\controller\SipGatewayController;
use app\middleware\admin\OpenApiAuth;

Route::group('/api/admin', function () {
    // 登录认证
    Route::group('/auth', function () {
        Route::get('/config', [SettingController::class, 'getSecure'])->name('admin.login_config');
        Route::post('/login', [AuthController::class, 'login'])->name('admin.login');
        Route::get('/captcha', [AuthController::class, 'captcha'])->name('admin.captcha');
        Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout')->middleware([
            AuthIdentityMiddleware::class,
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
        PermissionCheckMiddleware::class,
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
        PermissionCheckMiddleware::class,
    ]);

    // 管理员
    Route::group('/user', function () {
        Route::get('', [UserController::class, 'index'])->name('admin.user.index');
        Route::get('/{id:\d+}', [UserController::class, 'show'])->name('admin.user.show');
        Route::get('/uuid', [UserController::class, 'showUuid'])->name('admin.user.show-uuid');
        Route::post('', [UserController::class, 'store'])->name('admin.user.store');
        Route::put('/{id:\d+}', [UserController::class, 'update'])->name('admin.user.update');
        Route::delete('/{id:\d+}', [UserController::class, 'destroy'])->name('admin.user.destroy');
        Route::post('/{id:\d+}/roles', [UserController::class, 'assignRoles'])->name('admin.user.assign-roles');
        Route::post('/{id:\d+}/reset-password', [UserController::class, 'resetPassword'])->name('admin.user.reset-password');
        Route::post('/{id:\d+}/toggle-lock', [UserController::class, 'toggleLock'])->name('admin.user.toggle-lock');
        Route::post('/batch-delete', [UserController::class, 'batchDelete'])->name('admin.user.batch-delete');
        Route::get('/role-options', [UserController::class, 'roleOptions'])->name('admin.user.role-options');

        // API Key 管理
        Route::post('/{id:\d+}/api-key', [UserController::class, 'generateApiKey'])->name('admin.user.generate-api-key');
        Route::post('/{id:\d+}/api-key/toggle', [UserController::class, 'toggleApiKey'])->name('admin.user.toggle-api-key');
        //        Route::get('/menus', [UserController::class, 'getMenuAdmin'])->name('user.menus');
    })->middleware([
        //        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class,
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
        PermissionCheckMiddleware::class,
    ]);
    // 会员
    Route::group('/vip', function () {

    })->middleware([
        //        BasicAuthIdentity::class,
        AuthIdentityMiddleware::class,
    ]);

    // 作品
    Route::group('/product', function () {
        Route::get('/catalog/tree', [ProductCatalogController::class, 'tree'])->name('product.catalog-tree');

        Route::resource('/catalog', ProductCatalogController::class, [
            'index', 'store', 'show', 'update', 'destroy',
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
        PermissionCheckMiddleware::class,
    ]);


    // 附件管理
    Route::group('/attachment', function () {
        Route::get('/group/trees', [AttachmentGroupController::class, 'trees'])->name('attachment-group.trees');
        Route::resource('/group', AttachmentGroupController::class, [
            'index', 'store', 'show', 'update', 'destroy',
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
        PermissionCheckMiddleware::class,
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
        PermissionCheckMiddleware::class,
    ]);

    // 报警计划管理
    Route::group('/alarm-plan', function () {
        Route::get('', [AlarmPlanController::class, 'index'])->name('admin.alarm-plan.index');
        Route::get('/{id:\d+}', [AlarmPlanController::class, 'show'])->name('admin.alarm-plan.show');
        Route::post('', [AlarmPlanController::class, 'store'])->name('admin.alarm-plan.store');
        Route::put('/{id:\d+}', [AlarmPlanController::class, 'update'])->name('admin.alarm-plan.update');
        Route::delete('/{id:\d+}', [AlarmPlanController::class, 'destroy'])->name('admin.alarm-plan.destroy');
        Route::post('/{id:\d+}/channels', [AlarmPlanController::class, 'bindChannels'])->name('admin.alarm-plan.bind-channels');
        Route::get('/{id:\d+}/channels', [AlarmPlanController::class, 'boundChannels'])->name('admin.alarm-plan.bound-channels');
        Route::delete('/{id:\d+}/channels/{channelId:\w+}', [AlarmPlanController::class, 'unbindChannel'])->name('admin.alarm-plan.unbind-channel');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class,
    ]);

    Route::group('/gb28181', function () {
        // 设备管理
        Route::group('/devices', function () {
            Route::get('', [GB28181DeviceController::class, 'index'])->name('admin.gb28181.devices.index');
            Route::get('/tree', [GB28181DeviceController::class, 'tree'])->name('admin.gb28181.devices.tree');
            Route::get('/{id}', [GB28181DeviceController::class, 'show'])->name('admin.gb28181.devices.show');
            Route::put('/{id}', [GB28181DeviceController::class, 'update'])->name('admin.gb28181.devices.update');
            Route::post('/{id}/cmd', [GB28181DeviceController::class, 'cmd'])->name('admin.gb28181.devices.cmd');
            Route::delete('/{id}', [GB28181DeviceController::class, 'destroy'])->name('admin.gb28181.devices.destroy');
            Route::post('/{id}/catalog', [GB28181DeviceController::class, 'queryCatalog'])->name('admin.gb28181.devices.query-catalog');
            Route::get('/{id}/event-stream', [GB28181DeviceController::class, 'eventStream'])->name('admin.gb28181.devices.event-stream');
            Route::put('/batch/area', [GB28181DeviceController::class, 'batchUpdateArea'])->name('admin.gb28181.devices.batch-area');
            Route::post('/ptz', [GB28181PTZController::class, 'control'])->name('admin.gb28181.ptz.control');
        });

        // 预置位管理
        Route::group('/presets', function () {
            Route::get('', [GB28181PTZController::class, 'getPresetList'])->name('admin.gb28181.presets.list');
            Route::post('', [GB28181PTZController::class, 'setPreset'])->name('admin.gb28181.presets.set');
            Route::post('/call', [GB28181PTZController::class, 'callPreset'])->name('admin.gb28181.presets.call');
            Route::post('/delete', [GB28181PTZController::class, 'deletePreset'])->name('admin.gb28181.presets.delete');
            Route::post('/query-from-device', [GB28181PTZController::class, 'queryPresetsFromDevice'])->name('admin.gb28181.presets.query-from-device');
        });

        // 自动扫描
        Route::post('/devices/scan', [GB28181PTZController::class, 'scanControl'])->name('admin.gb28181.ptz.scan');

        // 设备控制命令
        Route::group('/device-control', function () {
            Route::post('/reboot', [GB28181DeviceControlController::class, 'reboot'])->name('admin.gb28181.device-control.reboot');
            Route::post('/record', [GB28181DeviceControlController::class, 'record'])->name('admin.gb28181.device-control.record');
            Route::post('/guard', [GB28181DeviceControlController::class, 'guard'])->name('admin.gb28181.device-control.guard');
            Route::post('/alarm-reset', [GB28181DeviceControlController::class, 'alarmReset'])->name('admin.gb28181.device-control.alarm-reset');
            Route::post('/iframe', [GB28181DeviceControlController::class, 'iFrame'])->name('admin.gb28181.device-control.iframe');
            Route::post('/home-position', [GB28181DeviceControlController::class, 'homePosition'])->name('admin.gb28181.device-control.home-position');
            Route::post('/drag-zoom', [GB28181DeviceControlController::class, 'dragZoom'])->name('admin.gb28181.device-control.drag-zoom');
            Route::post('/config', [GB28181DeviceControlController::class, 'config'])->name('admin.gb28181.device-control.config');
            Route::post('/wiper', [GB28181DeviceControlController::class, 'wiper'])->name('admin.gb28181.device-control.wiper');
            Route::post('/aux-switch', [GB28181DeviceControlController::class, 'auxSwitch'])->name('admin.gb28181.device-control.aux-switch');
            Route::post('/config-query', [GB28181DeviceControlController::class, 'configQuery'])->name('admin.gb28181.device-control.config-query');
        });

        // 通道管理
        Route::group('/channels', function () {
            Route::get('', [GB28181ChannelController::class, 'index'])->name('admin.gb28181.channels.index');
            Route::get('/{id}', [GB28181ChannelController::class, 'show'])->name('admin.gb28181.channels.show');
            Route::put('/{id}', [GB28181ChannelController::class, 'update'])->name('admin.gb28181.channels.update');
            Route::delete('/{id}', [GB28181ChannelController::class, 'destroy'])->name('admin.gb28181.channels.destroy');
            Route::put('/batch/bind-media', [GB28181ChannelController::class, 'batchBindMedia'])->name('admin.gb28181.channels.batch-bind-media');
            Route::get('/type/filters', [GB28181ChannelController::class, 'filterChannelTypes'])->name('admin.gb28181.devices.filter-channel-types');
            Route::get('/type/options', [GB28181ChannelController::class, 'channelTypeOptions'])->name('admin.gb28181.channels.type-options');
            Route::post('/codec-info', [GB28181ChannelController::class, 'getUrlCodecInfo'])->name('admin.gb28181.channels.codec-info');
            Route::post('/{id}/playback/query', [GB28181ChannelController::class, 'queryPlayback'])->name('admin.gb28181.streams.query-playback');
            Route::post('/{id}/playback/control', [GB28181StreamController::class, 'playbackControl'])->name('admin.gb28181.streams.control-playback');
            Route::post('/{id}/playback/download', [GB28181StreamController::class, 'playbackDownload'])->name('admin.gb28181.streams.download-playback');
            Route::get('/{id}/record-info-result', [GB28181ChannelController::class, 'getRecordInfoResult']);
        });

        // PTZ控制
        // 视频流管理
        Route::group('/streams', function () {
            Route::post('/start-live', [GB28181StreamController::class, 'startLive'])->name('admin.gb28181.streams.start-live');
            Route::post('/stop-live', [GB28181StreamController::class, 'stopLive'])->name('admin.gb28181.streams.stop-live');
            //            Route::get('/play-urls', [GB28181StreamController::class, 'getPlayUrls'])->name('admin.gb28181.streams.play-urls');
            Route::post('/playback/start', [GB28181StreamController::class, 'startPlayback'])->name('admin.gb28181.streams.start-playback');
            Route::post('/playback/stop', [GB28181StreamController::class, 'stopPlayback'])->name('admin.gb28181.streams.stop-playback');
        });

        // 录像任务管理
        Route::group('/record-tasks', function () {
            Route::get('', [GB28181RecordTaskController::class, 'index'])->name('admin.gb28181.record-tasks.index');
            Route::delete('/{id:\d+}', [GB28181RecordTaskController::class, 'destroy'])->name('admin.gb28181.record-tasks.destroy');
        });

        // GB28181 语音对讲/广播
        Route::group('/broadcast', function () {
            Route::post('/start', [\app\admin\controller\GB28181BroadcastController::class, 'start']);
            Route::post('/stop', [\app\admin\controller\GB28181BroadcastController::class, 'stop']);
        });

        // 录像计划管理
        Route::group('/record-plans', function () {
            Route::get('', [GB28181RecordPlanController::class, 'index'])->name('admin.gb28181.record-plans.index');
            Route::get('/{id:\d+}', [GB28181RecordPlanController::class, 'show'])->name('admin.gb28181.record-plans.show');
            Route::post('', [GB28181RecordPlanController::class, 'store'])->name('admin.gb28181.record-plans.store');
            Route::put('/{id:\d+}', [GB28181RecordPlanController::class, 'update'])->name('admin.gb28181.record-plans.update');
            Route::delete('/{id:\d+}', [GB28181RecordPlanController::class, 'destroy'])->name('admin.gb28181.record-plans.destroy');
            Route::post('/{id:\d+}/toggle', [GB28181RecordPlanController::class, 'toggle'])->name('admin.gb28181.record-plans.toggle');
            Route::post('/{id:\d+}/ranges', [GB28181RecordPlanController::class, 'setRanges'])->name('admin.gb28181.record-plans.ranges');
            Route::get('/{id:\d+}/channels', [GB28181RecordPlanController::class, 'boundChannels'])->name('admin.gb28181.record-plans.bound-channels');
            Route::post('/{id:\d+}/channels', [GB28181RecordPlanController::class, 'bindChannels'])->name('admin.gb28181.record-plans.bind-channels');
            Route::post('/channels/unbind', [GB28181RecordPlanController::class, 'unbindChannels'])->name('admin.gb28181.record-plans.unbind-channels');
            Route::delete('/channels/{channelId:\d+}', [GB28181RecordPlanController::class, 'unbindChannel'])->name('admin.gb28181.record-plans.unbind-channel');
        });

        // 云端录像文件
        Route::group('/recordings', function () {
            Route::get('', [GB28181RecordingController::class, 'index'])->name('admin.gb28181.recordings.index');
        });

        // 录像合并任务
        Route::group('/record-merge-tasks', function () {
            Route::get('', [GB28181RecordMergeController::class, 'index'])->name('admin.gb28181.record-merge-tasks.index');
            Route::get('/{id:\d+}', [GB28181RecordMergeController::class, 'show'])->name('admin.gb28181.record-merge-tasks.show');
            Route::post('', [GB28181RecordMergeController::class, 'store'])->name('admin.gb28181.record-merge-tasks.store');
            Route::post('/{id:\d+}/cancel', [GB28181RecordMergeController::class, 'cancel'])->name('admin.gb28181.record-merge-tasks.cancel');
            Route::delete('/{id:\d+}', [GB28181RecordMergeController::class, 'destroy'])->name('admin.gb28181.record-merge-tasks.destroy');
        });

        // 设备位置管理
        Route::group('/device-positions', function () {
            Route::get('', [GB28181DevicePositionController::class, 'index'])->name('admin.gb28181.device-positions.index');
            Route::get('/latest/{deviceId}', [GB28181DevicePositionController::class, 'latest'])->name('admin.gb28181.device-positions.latest');
            Route::get('/track/{deviceId}', [GB28181DevicePositionController::class, 'track'])->name('admin.gb28181.device-positions.track');
            Route::get('/map/points', [GB28181DevicePositionController::class, 'mapPoints'])->name('admin.gb28181.device-positions.map-points');
            Route::get('/map/tracks', [GB28181DevicePositionController::class, 'mapTracks'])->name('admin.gb28181.device-positions.map-tracks');
        });

        // 设备分类管理
        Route::group('/device-categories', function () {
            Route::get('/options', [GB28181DeviceCategoryController::class, 'options'])->name('admin.gb28181.device-categories.options');
            Route::get('/statistics', [GB28181DeviceCategoryController::class, 'statistics'])->name('admin.gb28181.device-categories.statistics');
        });
        Route::put('/devices/{deviceId}/category', [GB28181DeviceCategoryController::class, 'update'])->name('admin.gb28181.devices.update-category');

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
        PermissionCheckMiddleware::class,
    ]);

    Route::group('/media-server', function () {
        // TODO: 需要实现
        Route::get('', [MediaServerController::class, 'index'])->name('media-server.index');
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
        PermissionCheckMiddleware::class,
    ]);

    // 流代理管理
    Route::group('/stream-proxies', function () {
        Route::get('', [StreamProxyController::class, 'index'])->name('admin.stream-proxies.index');
        Route::post('', [StreamProxyController::class, 'create'])->name('admin.stream-proxies.create');
        Route::get('/summary', [StreamProxyController::class, 'summary'])->name('admin.stream-proxies.summary');
        Route::get('/{id:\d+}', [StreamProxyController::class, 'show'])->name('admin.stream-proxies.show');
        Route::put('/{id:\d+}', [StreamProxyController::class, 'update'])->name('admin.stream-proxies.update');
        Route::delete('/{id:\d+}', [StreamProxyController::class, 'destroy'])->name('admin.stream-proxies.destroy');

        // Stream control
        Route::post('/{id:\d+}/start', [StreamProxyController::class, 'start'])->name('admin.stream-proxies.start');
        Route::post('/{id:\d+}/stop', [StreamProxyController::class, 'stop'])->name('admin.stream-proxies.stop');
        Route::post('/{id:\d+}/restart', [StreamProxyController::class, 'restart'])->name('admin.stream-proxies.restart');

        // Play URLs
        Route::get('/{id:\d+}/play-urls', [StreamProxyController::class, 'playUrls'])->name('admin.stream-proxies.play-urls');
        Route::get('/{id:\d+}/push-url', [StreamProxyController::class, 'pushUrl'])->name('admin.stream-proxies.push-url');

        // Record plan
        Route::post('/{id:\d+}/bind-plan', [StreamProxyController::class, 'bindPlan'])->name('admin.stream-proxies.bind-plan');
        Route::post('/{id:\d+}/unbind-plan', [StreamProxyController::class, 'unbindPlan'])->name('admin.stream-proxies.unbind-plan');

        // Health check
        Route::post('/health-check', [StreamProxyController::class, 'healthCheck'])->name('admin.stream-proxies.health-check');

        // Logs
        Route::get('/{id:\d+}/logs', [StreamProxyController::class, 'logs'])->name('admin.stream-proxies.logs');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class,
    ]);

    // Stream Proxy Logs (standalone routes)
    Route::group('/stream-proxy-logs', function () {
        Route::get('', [StreamProxyController::class, 'allLogs'])->name('admin.stream-proxy-logs.index');
        Route::post('/cleanup', [StreamProxyController::class, 'cleanupLogs'])->name('admin.stream-proxy-logs.cleanup');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class,
    ]);

    // SIP Gateway management
    Route::group('/sip-gateways', function () {
        Route::get('', [SipGatewayController::class, 'index'])->name('admin.sip-gateways.index');
        Route::get('/{id:\d+}', [SipGatewayController::class, 'show'])->name('admin.sip-gateways.show');
        Route::post('', [SipGatewayController::class, 'store'])->name('admin.sip-gateways.store');
        Route::put('/{id:\d+}', [SipGatewayController::class, 'update'])->name('admin.sip-gateways.update');
        Route::delete('/{id:\d+}', [SipGatewayController::class, 'destroy'])->name('admin.sip-gateways.destroy');
        Route::post('/{id:\d+}/toggle', [SipGatewayController::class, 'toggle'])->name('admin.sip-gateways.toggle');
        Route::post('/bind', [SipGatewayController::class, 'bindDevices'])->name('admin.sip-gateways.bind');
        Route::post('/unbind', [SipGatewayController::class, 'unbindDevices'])->name('admin.sip-gateways.unbind');
    })->middleware([
        AuthIdentityMiddleware::class,
        PermissionCheckMiddleware::class,
    ]);

    // OpenAPI - 支持 API Key 认证的白名单路由
    Route::group('/open-api', function () {
        // 录像文件查询（按设备、通道、时间范围）
        Route::get('/recordings', [GB28181RecordingController::class, 'index'])->name('admin.open-api.recordings.index');
        Route::get('/recordings/{id:\d+}', [GB28181RecordingController::class, 'show'])->name('admin.open-api.recordings.show');

        // 录像控制
        Route::post('/recordings/start-record', [GB28181RecordingController::class, 'startRecord'])->name('admin.open-api.recordings.start-record');
        Route::post('/recordings/stop-record', [GB28181RecordingController::class, 'stopRecord'])->name('admin.open-api.recordings.stop-record');

        // 设备查询
        Route::get('/devices', [GB28181DeviceController::class, 'index'])->name('admin.open-api.devices.index');
        Route::get('/devices/{id:\d+}', [GB28181DeviceController::class, 'show'])->name('admin.open-api.devices.show');

        // 通道查询
        Route::get('/channels', [GB28181ChannelController::class, 'index'])->name('admin.open-api.channels.index');
        Route::get('/channels/{id:\d+}', [GB28181ChannelController::class, 'show'])->name('admin.open-api.channels.show');

        // 录像计划查询
        Route::get('/record-plans', [GB28181RecordPlanController::class, 'index'])->name('admin.open-api.record-plans.index');
        Route::get('/record-plans/{id:\d+}', [GB28181RecordPlanController::class, 'show'])->name('admin.open-api.record-plans.show');

        // 流代理查询
        Route::get('/stream-proxies', [StreamProxyController::class, 'index'])->name('admin.open-api.stream-proxies.index');
        Route::get('/stream-proxies/{id:\d+}', [StreamProxyController::class, 'show'])->name('admin.open-api.stream-proxies.show');

        // 设备树
        Route::get('/devices/tree', [GB28181DeviceController::class, 'tree'])->name('admin.open-api.devices.tree');

        // 直播控制
        Route::post('/streams/start-live', [GB28181StreamController::class, 'startLive'])->name('admin.open-api.streams.start-live');
        Route::post('/streams/stop-live', [GB28181StreamController::class, 'stopLive'])->name('admin.open-api.streams.stop-live');

        // PTZ 控制
        Route::post('/ptz/control', [GB28181PTZController::class, 'control'])->name('admin.open-api.ptz.control');

        // 预置位管理
        Route::get('/presets', [GB28181PTZController::class, 'getPresetList'])->name('admin.open-api.presets.list');
        Route::post('/presets', [GB28181PTZController::class, 'setPreset'])->name('admin.open-api.presets.set');
        Route::post('/presets/call', [GB28181PTZController::class, 'callPreset'])->name('admin.open-api.presets.call');
        Route::post('/presets/delete', [GB28181PTZController::class, 'deletePreset'])->name('admin.open-api.presets.delete');
    })->middleware([
        OpenApiAuth::class,
        PermissionCheckMiddleware::class,
    ]);
});