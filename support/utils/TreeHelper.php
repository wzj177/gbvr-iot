<?php


namespace support\utils;


class TreeHelper
{
    /**
     * [reference_delivery_tree 引用传值实现无限级分类]
     * @param  [type] $list  [数据源]
     * @param string $pk [主键key]
     * @param string $pid [上级key]
     * @param string $child [子数据key]
     * @return [array]        [无限级分类数据结构]
     */
    public static function referenceDeliveryTree($list, $pk = 'id', $pid = 'pid', $child = 'children')
    {
        $refer = []; // 创建一个空数组用于保存节点的引用
        $tree = []; // 创建一个空数组用于保存树形结构

        // 将列表中的每个节点的引用存储在 $refer 数组中
        foreach ($list as $key => $data) {
            $refer[$data[$pk]] =& $list[$key];
            $refer[$data[$pk]][$child] = []; // 初始化 children 数组
        }

        // 遍历列表，构建树形结构
        foreach ($list as $key => $data) {
            $parentId = $data[$pid];
            // 判断是否为根节点
            if ($parentId === null || !isset($refer[$parentId])) {
                $tree[] =& $list[$key];
            } else {
                // 将当前节点添加到父节点的 children 数组中
                $parent =& $refer[$parentId];
                $parent[$child][] =& $list[$key];
            }
        }

        return $tree;
    }

    public static function getTreeSelectOptions($list, $pk = 'id', $pid = 'pid', $child = 'children', $root = 0, $key = 'name')
    {
        $results = [];
        self::getSelectOptions(self::referenceDeliveryTree($list, $pk, $pid, $child, $root), $results, $key);

        return $results;
    }

    public static function getSelectOptions($tree = [], &$result = [], $key = 'name', $deep = 0, $separator = "　　")
    {
        $deep++;
        foreach ($tree as $list) {
            $result[$list['id']] = $deep == 1 ? str_repeat($separator, $deep - 1) . $list[$key] : str_repeat($separator, $deep - 1) . '├' . $list[$key];
            if (isset($list['children'])) {
                self::getSelectOptions($list['children'], $result, $key, $deep);
            }
        }

        return $result;
    }


    /**
     * [递归创建子孙树]
     * @param  [Array]  $data      [数据源]
     * @param  [int]  $parent_id [默认从最远的根开始]
     * @param  [string]  $pk [key]
     * @param integer $lv [排序]
     * @return [Array]             [tree]
     */
    public static function spanningTree($data, $parent_id = null, $pk = 'parent_id', $lv = 0)
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value[$pk] == $parent_id) {
                $value['lv'] = $lv;
                $result[] = $value;
                $result = array_merge($result, self::spanningTree($data, $value['id'], $pk, $lv + 1));
            }
        }

        return $result;
    }

    /**
     * [递归创建子孙树-对象]
     * @param  [Object]  $object      [数据源]
     * @param  [int]  $parent_id [默认从最远的根开始]
     * @param integer $lv [排序]
     * @return [Array Object]             [对象数组]
     */
    public static function spanningObjectTree($object, $parent_id, $lv = 0)
    {
        $result = [];
        foreach ($object as $obj) {
            if ($obj->parent_id == $parent_id) {
                $obj->lv = $lv;
                $result[] = $obj;
                $result = array_merge($result, self::spanningObjectTree($object, $obj->id, $lv + 1));
            }
        }

        return $result;
    }

    /**
     * [递归创建子孙树-对象 2]
     * @param  [type] $object    [数据源]
     * @param  [type] $pkey [上级IDkey]
     * @param  [type] $parent_id [默认从最远的根开始]
     * @param  [type] $lv        [排序]
     * @return [type]            [对象数组]
     */
    public static function createObjectTree($object, $pkey = 'parent_id', $parent_id = 0, $lv = 1)
    {
        $result = [];
        foreach ($object as $obj) {
            if ($obj->{$pkey} === $parent_id) {
                isset($object->lv) and $obj->lv = $lv;
                $result[] = $obj;
                $obj->childrens = self::createObjectTree($object, $pkey, $obj->id, $lv + 1);
            }
        }

        return $result;
    }

    /**
     * [递归创建家谱树]
     * @param  [Array] $data [数据源]
     * @param  [type] $id   [description]
     * @return [Array]       [description]
     */
    public static function spanningParentTree($id, $data = [])
    {
        $result = [];
        foreach ($data as $key => $da) {
            if ($da['id'] == $id) {
                $result[] = $da;// 这种结果是从到自己祖父节点
                if ($da['parent_id']) {
                    $result = array_merge($result, self::spanningParentTree($da['parent_id'], $data));
                }
                // $result[] = $da;// 这种结果是从祖父节点到自己
            }
        }

        return $result;
    }

    /**
     * [迭代创建家谱树]
     *
     * @param $arr
     * @param $id
     * @param string $key
     * @return array
     */
    public static function iterationParentTree($arr, $id, $key = 'parent_id')
    {
        $tree = [];
        while ($id) {
            foreach ($arr as $v) {
                if ($v['id'] == $id) {
                    $tree[] = $v;
                    $id = $v[$key];
                    break;
                }
            }
            if (!$tree) {
                break;
            }
        }

        return $tree;
    }


    /**
     * @param $arr
     * @param $parentId
     * @param string $key
     * @return array
     */
    public static function iterationTree($arr, $parentId, $key = 'id')
    {
        $tree = [];
        while ($parentId) {
            foreach ($arr as $v) {
                if ($v['parent_id'] == $parentId) {
                    $tree[] = $v;
                    $parentId = $v[$key];
                    break;
                }
            }
            if (!$tree) {
                break;
            }
        }

        return $tree;
    }
}
