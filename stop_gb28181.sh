#!/bin/bash

# GB28181 停止脚本

echo "======================================"
echo "  GB28181 服务停止"
echo "======================================"
echo ""

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# 停止信令网关
echo -e "${YELLOW}停止信令网关...${NC}"
if [ -f /tmp/gb28181_gateway.pid ]; then
    GATEWAY_PID=$(cat /tmp/gb28181_gateway.pid)
    if ps -p $GATEWAY_PID > /dev/null; then
        kill $GATEWAY_PID
        echo -e "${GREEN}✓ 信令网关已停止 (PID: $GATEWAY_PID)${NC}"
        rm /tmp/gb28181_gateway.pid
    else
        echo "信令网关未运行"
    fi
else
    # 尝试通过进程名杀死
    pkill -f "gb28181_server.php"
    echo -e "${GREEN}✓ 信令网关已停止${NC}"
fi

# 停止API服务
echo -e "${YELLOW}停止API服务...${NC}"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR/gbvr-iot" || exit
php start.php stop > /dev/null 2>&1
echo -e "${GREEN}✓ API服务已停止${NC}"

echo ""
echo -e "${GREEN}所有服务已停止${NC}"
