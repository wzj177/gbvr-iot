<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Request;
use Webman\Route;

Route::get('/', function (Request $request) {
    return view('index/view', ['name' => config('app.name')]);
});
Route::get('/index', function ($request) {
    return view('index/view', ['name' => config('app.name')]);
});
$folders = [
    app_path('admin/config/routes'),
    app_path('api/config/routes'),
];
foreach ($folders as $folder) {
    if (is_dir($folder)) {
        foreach (glob("{$folder}/*.php") as $filename) {
            include_once($filename);
        }
    }
}


Route::fallback(function (Request $request) {
    // && config('app.debug')
    if (strtoupper($request->method()) === 'OPTIONS') {
        $response = response('', 204);
    } elseif ($request->isAjax()
        || $request->isPjax()
        || strpos(strtolower($request->header('content-type')), 'application/json') !== false) {
        $response = json([
            'code' => 4041001,
            'message' => '404 not found!!!',
            'data' => null
        ])->withStatus(404);
    } else {
        $response = view('404', ['error' => '404 not found!!!'])->withStatus(404);
    }

    if (config('app.debug')) {
        $response->withHeaders([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin' => $request->header('origin', '*'),
            'Access-Control-Allow-Methods' => $request->header('access-control-request-method', '*'),
            'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', '*'),
        ]);
    }


    return $response;
});
Route::disableDefaultRoute();

