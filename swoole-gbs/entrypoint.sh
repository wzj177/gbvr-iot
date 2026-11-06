#!/bin/sh
# Swoole 网关容器入口：依赖就绪检查 + webman 运行时准备，然后 exec 主命令
set -e

cd /app

echo "[entrypoint] PHP $(php -v | head -1 | awk '{print $2}') / Swoole $(php -r 'echo phpversion("swoole");')"

# webman 命令引导依赖 autoload（项目目录以卷挂载，宿主可能没装 vendor）
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor 缺失，执行 composer install..."
    composer install --no-interaction --no-progress --prefer-dist --no-dev
fi

# webman runtime 目录（bootstrap 会写入上传临时目录/日志/视图缓存）
mkdir -p runtime/logs runtime/views runtime/tmp

exec "$@"
