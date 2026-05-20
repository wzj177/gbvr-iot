<?php

namespace CoreW\Business\Product\Exception;

use CoreW\Exception\AbstractBizException;

class ProductException extends AbstractBizException
{
    const TAG_ADD_PARAMETER_ERROR = 4132401;
    const TAG_ADD_NEED_NULL_ERROR = 4132402;
    const CATALOG_NOTFOUND_ERROR = 4042401;
    const CATALOG_HAS_CHILD = 4002401;
    const PRODUCT_CREATE_SCENE_EMPTY_ERROR = 4002501;
    const CREATE_SCENE_UPLOAD_ERROR = 4002504;

    const NOT_FOUND_PRODUCT = 4042402;
    const NOT_FOUND_PRODUCT_SCENE = 4042403;

    const SET_PRODUCT_TOUR_NODES_EMPTY_NODES = 4002502;
    const CREATE_PRODUCT_PLANE_GRAPH_MARKERS_EMPTY_NODES = 4002503;
    const NOT_FOUND_PRODUCT_TOUR_NODE = 4042404;

    const CREATE_PRODUCT_PLANE_GRAPH_IMG_PATH_EMPTY = 4002504;
    const PRODUCT_VIEW_PWD_CHECK_ERROR = 4032501;
    const PRODUCT_SHARE_EXPIRED = 4042405;

    const PRODUCT_FORBIDDEN_CLOSE_USER_PRODUCT = 4032502;

    const PRODUCT_CLOSED = 4002505;
    const PRODUCT_PUBLISHED = 4002506;

    public function __construct($code, $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    /*
     * @return array|array[] 
     */
    public function setMessages()
    {
        $this->messages = [
            self::TAG_ADD_PARAMETER_ERROR                        => '添加失败，参数错误',
            self::TAG_ADD_NEED_NULL_ERROR                        => '添加失败，无可新增项',
            self::CATALOG_NOTFOUND_ERROR                         => '分类不存在',
            self::CATALOG_HAS_CHILD                              => '无法删除，存在下级',
            self::PRODUCT_CREATE_SCENE_EMPTY_ERROR               => '作品创建失败，请至少上传一张全景图',
            self::CREATE_SCENE_UPLOAD_ERROR                      => '场景创建失败，图片上传出错,请联系管理员处理',
            self::NOT_FOUND_PRODUCT                              => '作品不存在',
            self::NOT_FOUND_PRODUCT_SCENE                        => '场景不存在',
            self::SET_PRODUCT_TOUR_NODES_EMPTY_NODES             => '导游节点记录失败,节点为空',
            self::CREATE_PRODUCT_PLANE_GRAPH_MARKERS_EMPTY_NODES => '创建地图场景点位失败,点位为空',
            self::NOT_FOUND_PRODUCT_TOUR_NODE                    => '导游节点不存在',
            self::CREATE_PRODUCT_PLANE_GRAPH_IMG_PATH_EMPTY      => '创建地图场景点位失败,平面图必须上传',
            self::PRODUCT_VIEW_PWD_CHECK_ERROR                   => '密码输入错误,无权限访问',
            self::PRODUCT_SHARE_EXPIRED                          => '访问失败,分享链接已失期',
            self::PRODUCT_FORBIDDEN_CLOSE_USER_PRODUCT           => '操作失败,无权限关闭此作品',
            self::PRODUCT_CLOSED                                 => '操作失败,作品已关闭',
            self::PRODUCT_PUBLISHED                              => '操作失败,作品已发布',
        ];
    }

}
