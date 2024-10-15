<?php


namespace CoreW;


use Webman\Route;

class CustomRoute extends Route
{
    public static function resource(string $name, string $controller, array $options = [], string $customNameFix = '')
    {
        $name = trim($name, '/');
        $routeNameFix = !empty($customNameFix) ? $customNameFix : $name;
        if (is_array($options) && !empty($options)) {
            $diffOptions = array_diff($options, ['index', 'create', 'store', 'update', 'show', 'edit', 'destroy', 'recovery']);
            if (!empty($diffOptions)) {
                foreach ($diffOptions as $action) {
                    static::any("/$name/{$action}[/{id}]", [$controller, $action])->name("$routeNameFix.{$action}");
                }
            }
            // 注册路由 由于顺序不同会导致路由无效 因此不适用循环注册
            if (in_array('index', $options)) static::get("/$name", [$controller, 'index'])->name("$routeNameFix.index");
            if (in_array('create', $options)) static::get("/$name/create", [$controller, 'create'])->name("$routeNameFix.create");
            if (in_array('store', $options)) static::post("/$name", [$controller, 'store'])->name("$routeNameFix.store");
            if (in_array('update', $options)) static::put("/$name/{id}", [$controller, 'update'])->name("$routeNameFix.update");
            if (in_array('show', $options)) static::get("/$name/{id}", [$controller, 'show'])->name("$routeNameFix.show");
            if (in_array('edit', $options)) static::get("/$name/{id}/edit", [$controller, 'edit'])->name("$routeNameFix.edit");
            if (in_array('destroy', $options)) static::delete("/$name/{id}", [$controller, 'destroy'])->name("$routeNameFix.destroy");
            if (in_array('recovery', $options)) static::put("/$name/{id}/recovery", [$controller, 'recovery'])->name("$routeNameFix.recovery");
        } else {
            //为空时自动注册所有常用路由
            if (method_exists($controller, 'index')) static::get("/$name", [$controller, 'index'])->name("$routeNameFix.index");
            if (method_exists($controller, 'create')) static::get("/$name/create", [$controller, 'create'])->name("$routeNameFix.create");
            if (method_exists($controller, 'store')) static::post("/$name", [$controller, 'store'])->name("$routeNameFix.store");
            if (method_exists($controller, 'update')) static::put("/$name/{id}", [$controller, 'update'])->name("$routeNameFix.update");
            if (method_exists($controller, 'show')) static::get("/$name/{id}", [$controller, 'show'])->name("$routeNameFix.show");
            if (method_exists($controller, 'edit')) static::get("/$name/{id}/edit", [$controller, 'edit'])->name("$routeNameFix.edit");
            if (method_exists($controller, 'destroy')) static::delete("/$name/{id}", [$controller, 'destroy'])->name("$routeNameFix.destroy");
            if (method_exists($controller, 'recovery')) static::put("/$name/{id}/recovery", [$controller, 'recovery'])->name("$routeNameFix.recovery");
        }
    }
}