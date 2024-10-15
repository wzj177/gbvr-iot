<?php

use app\admin\controller\AttachmentController;
use app\admin\controller\AttachmentGroupController;
use app\admin\controller\AuthController;
use app\admin\controller\ProductCatalogController;
use app\admin\controller\ProductTagController;
use app\admin\controller\SettingController;
use app\admin\controller\SystemController;
use app\admin\controller\UserController;
use app\middleware\admin\BasicAuthIdentityMiddleware;
use app\middleware\admin\XAuthTokenIdentityMiddleware;
use CoreW\CustomRoute as Route;
use function Swoole\Coroutine\Http\post;

Route::group('/admin', function () {
    // 登录认证
    Route::group('/auth', function () {
        Route::get('/config', [SettingController::class, 'getSecure'])->name('admin.login_config');
        Route::post('/login', [AuthController::class, 'login'])->name('admin.login')->middleware([
            BasicAuthIdentityMiddleware::class,
        ]);
        Route::get('/captcha', [AuthController::class, 'captcha'])->name('admin.captcha');
        Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout')->middleware([
            XAuthTokenIdentityMiddleware::class
        ]);
    });

    // 系统
    Route::group('/system', function () {
        Route::get('/log', [SystemController::class, 'logs'])->name('system.logs');
        Route::get('/log/{id:\d+}', [SystemController::class, 'log'])->name('system.log');
        Route::post('/log/btch-dlt', [SystemController::class, 'batchDelete'])->name('system.log.batch-delete');
        Route::post('/cache-clear', [SystemController::class, 'clearCache'])->name('system.cache-clear');
        Route::post('/test-mail', [SystemController::class, 'testMail'])->name('system.test-mail');
        Route::get('/log/modules', [SystemController::class, 'logModuleOptions'])->name('system.log.module-options');
        Route::get('/log/actions/{module:\w+}', [SystemController::class, 'logActionOptions'])->name('system.log.action-options');
    })->middleware([
//        BasicAuthIdentity::class,
        XAuthTokenIdentityMiddleware::class
    ]);

    // 菜单
    Route::group('/menu', function () {
    })->middleware([
//        BasicAuthIdentity::class,
        XAuthTokenIdentityMiddleware::class
    ]);

    // 管理员
    Route::group('/user', function () {
        Route::get('/menus', [UserController::class, 'getMenuAdmin'])->name('user.menus');
    })->middleware([
//        BasicAuthIdentity::class,
        XAuthTokenIdentityMiddleware::class
    ]);

    // 角色权限
    Route::group('/role', function () {

    })->middleware([
//        BasicAuthIdentity::class,
        XAuthTokenIdentityMiddleware::class
    ]);
    // 会员
    Route::group('/vip', function () {

    })->middleware([
//        BasicAuthIdentity::class,
        XAuthTokenIdentityMiddleware::class
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
        XAuthTokenIdentityMiddleware::class
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
        XAuthTokenIdentityMiddleware::class
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
        XAuthTokenIdentityMiddleware::class
    ]);
});
