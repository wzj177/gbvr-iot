#!/bin/bash

# GB28181 快速启动脚本

echo "======================================"
echo "  GB28181 信令网关 + API 快速启动"
echo "======================================"
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 检查ZLMediaKit
echo -e "${YELLOW}[1/4] 检查ZLMediaKit...${NC}"
if pgrep -x "MediaServer" > /dev/null; then
    echo -e "${GREEN}✓ ZLMediaKit已运行${NC}"
else
    echo -e "${RED}✗ ZLMediaKit未运行${NC}"
    echo "请先启动ZLMediaKit："
    echo "  cd /path/to/ZLMediaKit"
    echo "  ./MediaServer -d"
    exit 1
fi

# 检查Redis
echo -e "${YELLOW}[2/4] 检查Redis...${NC}"
if redis-cli ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Redis已运行${NC}"
else
    echo -e "${RED}✗ Redis未运行${NC}"
    echo "请先启动Redis："
    echo "  redis-server"
    exit 1
fi

# 检查MySQL
echo -e "${YELLOW}[3/4] 检查MySQL...${NC}"
if mysqladmin ping -h"127.0.0.1" --silent > /dev/null 2>&1; then
    echo -e "${GREEN}✓ MySQL已运行${NC}"
else
    echo -e "${RED}✗ MySQL未运行${NC}"
    echo "请先启动MySQL"
    exit 1
fi

# 检查数据库表
echo -e "${YELLOW}[4/4] 检查数据库表...${NC}"
DB_NAME="gbvr_iot"
TABLES=("devices" "device_channels" "stream_sessions")
ALL_EXIST=true

for table in "${TABLES[@]}"; do
    if mysql -h127.0.0.1 -uroot -e "USE $DB_NAME; DESCRIBE $table" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ 表 $table 存在${NC}"
    else
        echo -e "${RED}✗ 表 $table 不存在${NC}"
        ALL_EXIST=false
    fi
done

if [ "$ALL_EXIST" = false ]; then
    echo ""
    echo -e "${YELLOW}请先执行数据库迁移：${NC}"
    echo "  mysql -u root -p $DB_NAME < database/migrations/gb28181_tables.sql"
    exit 1
fi

echo ""
echo -e "${GREEN}======================================"
echo "  环境检查完成，开始启动服务"
echo "======================================${NC}"
echo ""

# 获取当前脚本目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

# 启动信令网关
echo -e "${YELLOW}启动信令网关...${NC}"
cd "$SCRIPT_DIR" || exit
php gb28181_server.php > /dev/null 2>&1 &
GATEWAY_PID=$!
echo -e "${GREEN}✓ 信令网关已启动 (PID: $GATEWAY_PID)${NC}"

# 等待信令网关初始化
sleep 2

# 检查信令网关是否正常
if ! ps -p $GATEWAY_PID > /dev/null; then
    echo -e "${RED}✗ 信令网关启动失败${NC}"
    exit 1
fi

# 启动API服务
echo -e "${YELLOW}启动API服务...${NC}"
cd "$SCRIPT_DIR/gbvr-iot" || exit
php start.php start -d > /dev/null 2>&1
echo -e "${GREEN}✓ API服务已启动${NC}"

echo ""
echo -e "${GREEN}======================================"
echo "  所有服务已启动"
echo "======================================${NC}"
echo ""
echo "服务状态："
echo "  - 信令网关: 0.0.0.0:5060 (SIP)"
echo "  - API服务: http://127.0.0.1:8787"
echo "  - ZLMediaKit: http://127.0.0.1:80"
echo ""
echo "日志查看："
echo "  - 信令网关: tail -f logs/gb28181.log"
echo "  - API服务: tail -f gbvr-iot/runtime/logs/sip.log"
echo ""
echo "停止服务："
echo "  ./stop_gb28181.sh"
echo ""

# 保存PID到文件
echo $GATEWAY_PID > /tmp/gb28181_gateway.pid
