<?php

namespace support\utils;

class GisUtil
{
    /**
     * @param array $polygon
     * @return array
     */
    public static function getVideoLayerPositionByPolygonCorners(array $polygonCorners): array
    {
        $items = [];
        foreach ($polygonCorners as $key => $corner) {
            $items[] = [
                'pitch' => $corner[1],
                'yaw' => $corner[0],
            ];
        }

        return $items;
    }

    /**
     * 计算宽度和高度
     *
     * @param $corners
     * @param int $webWidth
     * @param int $webHeight
     * @return float[]
     */
    public static function getPolygonCorners($polygon): array
    {
        // 初始化左上、右上、左下、右下的点
        $left_top = null;
        $right_top = null;
        $right_bottom = null;
        $left_bottom = null;

        foreach ($polygon as $point) {
            $yaw = $point[0];  // yaw
            $pitch = $point[1]; // pitch
            // 左上角：pitch 最大且 yaw 最小
            if (is_null($left_top) || $pitch > $left_top[1] || ($pitch == $left_top[1] && $yaw < $left_top[0])) {
                $left_top = [$yaw, $pitch];
            }

            // 右上角：pitch 最大且 yaw 最大
            if (is_null($right_top) || $pitch > $right_top[1] || ($pitch == $right_top[1] && $yaw > $right_top[0])) {
                $right_top = [$yaw, $pitch];
            }

            // 左下角：pitch 最小且 yaw 最小
            if (is_null($left_bottom) || $pitch < $left_bottom[1] || ($pitch == $left_bottom[1] && $yaw < $left_bottom[0])) {
                $left_bottom = [$yaw, $pitch];
            }

            // 右下角：pitch 最小且 yaw 最大
            if (is_null($right_bottom) || $pitch < $right_bottom[1] || ($pitch == $right_bottom[1] && $yaw > $right_bottom[0])) {
                $right_bottom = [$yaw, $pitch];
            }
        }

        return [
            'left_top' => $left_top,
            'right_top' => $right_top,
            'right_bottom' => $right_bottom,
            'left_bottom' => $left_bottom
        ];
    }

    public static function sphericalToCartesian($pitch, $yaw): array
    {
        // 将球面坐标 (pitch, yaw) 转换为 Cartesian (x, y)
        $x = cos($pitch) * cos($yaw);
        $y = cos($pitch) * sin($yaw);
        $z = sin($pitch);
        return [$x, $y, $z];
    }

    public static function calculateWidthHeight($corners, int $webWidth = 1920, int $webHeight = 1080): array
    {
        // 左上角和右下角坐标
        $left_top = $corners['left_top'];
        $right_bottom = $corners['right_bottom'];

        // 计算坐标范围
        $widthRange = abs($right_bottom[0] - $left_top[0]);
        $heightRange = abs($left_top[1] - $right_bottom[1]);

        // 假设坐标范围是已知的，例如：
        $xRange = 4.0; // 你要映射的实际坐标范围（x轴）
        $yRange = 1.0; // 你要映射的实际坐标范围（y轴）

        // 计算缩放因子
        $xScale = $webWidth / $xRange;
        $yScale = $webHeight / $yRange;

        // 计算宽度和高度（转换为像素）
        $width = $widthRange * $xScale;
        $height = $heightRange * $yScale;

        return [
            'width' => ceil($width),
            'height' => ceil($height)
        ];
    }

}