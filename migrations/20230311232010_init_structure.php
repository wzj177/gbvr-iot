<?php

use Phpmig\Migration\Migration;

class InitStructure extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
DROP TABLE IF EXISTS `gv_ip_blacklist`;
CREATE TABLE `gv_ip_blacklist`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `type` enum('failed','banned') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '禁用类型',
  `counter` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `expiredTime` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `createdTime` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '访问ip黑名单';
DROP TABLE IF EXISTS `gv_log`;
CREATE TABLE `gv_log`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '系统日志ID',
  `userId` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `module` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '日志所属模块',
  `action` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '日志所属操作类型',
  `message` text CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '日志内容',
  `data` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT '日志数据',
  `ip` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '日志记录IP',
  `createdTime` int(10) UNSIGNED NOT NULL COMMENT '日志发生时间',
  `level` char(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '日志等级',
  `ipArea` varchar(255) NOT NULL DEFAULT '' COMMENT '日志记录ip地址信息',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `userId`(`userId`) USING BTREE,
  INDEX `idx_module_action`(`module`, `action`) USING BTREE,
  INDEX `idx_action`(`action`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 70 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '操作日志';

DROP TABLE IF EXISTS `rate_limit`;
CREATE TABLE `rate_limit`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `_key` varchar(128) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `data` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `deadline` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `_key`(`_key`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = 'API请求限速';

DROP TABLE IF EXISTS `gv_role`;
CREATE TABLE `gv_role`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '权限名称',
  `code` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '权限代码',
  `data` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT '权限配置',
  `createdTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `createdUserId` int(10) UNSIGNED NOT NULL COMMENT '创建用户ID',
  `updatedTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '角色权限表';

DROP TABLE IF EXISTS `gv_setting`;
CREATE TABLE `gv_setting`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '系统设置ID',
  `name` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '系统设置名',
  `value` longblob NULL COMMENT '系统设置值',
  `namespace` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'default',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `name`(`name`, `namespace`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '系统设置表';

SQL);
        $container['db']->exec(<<<SQL
DROP TABLE IF EXISTS `gv_user`;
CREATE TABLE `gv_user`  (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `email` varchar(128) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '用户邮箱',
  `verifiedMobile` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `password` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '用户密码',
  `salt` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '密码SALT',
  `nickname` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '用户名',
  `avatar` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '小头像',
  `emailVerified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '邮箱是否为已验证',
  `roles` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '用户角色',
  `locked` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否被禁止',
  `lockDeadline` int(10) NOT NULL DEFAULT 0 COMMENT '帐号锁定期限',
  `consecutivePasswordErrorTimes` int(11) NOT NULL DEFAULT 0 COMMENT '帐号密码错误次数',
  `lastPasswordFailTime` int(10) NOT NULL DEFAULT 0,
  `loginTime` int(11) NOT NULL DEFAULT 0 COMMENT '最后登录时间',
  `loginIp` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `approvalTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '实名认证时间',
  `approvalStatus` enum('unapprove','approving','approved','approve_fail') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unapprove' COMMENT '实名认证状态',
  `createdIp` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '注册IP',
  `createdTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '注册时间',
  `updatedTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后更新时间',
  `orgId` int(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT '组织机构ID',
  `orgCode` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '1.' COMMENT '组织机构内部编码',
  `registeredWay` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '注册设备来源(web/ios/android)',
  `uuid` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '用户uuid',
  `passwordInit` tinyint(1) NOT NULL DEFAULT 1 COMMENT '初始化密码',
  `registerVisitId` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `destroyed` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否注销',
  `type` varchar(16) NOT NULL DEFAULT 'default' COMMENT '类型；default=默认；system=系统',
  `setup` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否初始化设置的，未初始化的可以设置邮箱、用户名',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `email`(`email`) USING BTREE,
  UNIQUE INDEX `nickname`(`nickname`) USING BTREE,
  UNIQUE INDEX `uuid`(`uuid`) USING BTREE,
  INDEX `updatedTime`(`updatedTime`) USING BTREE,
  INDEX `verifiedMobile`(`verifiedMobile`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '管理端用户表';
DROP TABLE IF EXISTS `gv_user_bind`;
CREATE TABLE `gv_user_bind`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户绑定ID',
  `type` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '用户绑定类型',
  `fromId` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '来源方用户ID',
  `toId` int(10) UNSIGNED NOT NULL COMMENT '被绑定的用户ID',
  `token` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT 'oauth token',
  `refreshToken` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT 'oauth refresh token',
  `expiredTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'token过期时间',
  `createdTime` int(10) UNSIGNED NOT NULL COMMENT '绑定时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `type`(`type`, `fromId`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '管理端用户关联绑定表';

DROP TABLE IF EXISTS `gv_user_org`;
CREATE TABLE `gv_user_org`  (
  `id` int(10) NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `userId` int(10) UNSIGNED NOT NULL COMMENT '用户ID',
  `orgId` int(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT '组织机构id',
  `orgCode` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '1.' COMMENT '组织机构内部编码',
  `createdTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `updatedTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `userId`(`userId`) USING BTREE,
  INDEX `orgCode`(`orgCode`) USING BTREE,
  INDEX `orgId`(`orgId`) USING BTREE,
  INDEX `idx_orgId_userId`(`orgId`, `userId`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = '管理端用户组织机构关系' ROW_FORMAT = Dynamic;

DROP TABLE IF EXISTS `gv_user_token`;
CREATE TABLE `gv_user_token`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'TOKEN编号',
  `token` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'TOKEN值',
  `userId` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKEN关联的用户ID',
  `type` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'TOKEN类型',
  `data` text CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'TOKEN数据',
  `times` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKEN的校验次数限制(0表示不限制)',
  `remainedTimes` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKE剩余校验次数',
  `expiredTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKEN过期时间',
  `createdTime` int(10) UNSIGNED NOT NULL COMMENT 'TOKEN创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `token`(`token`(60)) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '管理员用户token表';

DROP TABLE IF EXISTS `gv_vip`;
CREATE TABLE `gv_vip` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `uuid` varchar(64) NOT NULL COMMENT 'UUID',
  `nickname` varchar(128) NOT NULL COMMENT '用户名',
  `password` varchar(64) NOT NULL COMMENT '密码',
  `salt` varchar(32) NOT NULL COMMENT '密码盐',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `intro` varchar(255) DEFAULT '' COMMENT '简介',
  `anonymous` tinyint(1) NOT NULL DEFAULT '0' COMMENT '匿名用户；0=否；1=是',
  `inviteCode` varchar(16) NOT NULL COMMENT '邀请码',
  `email` varchar(128) NOT NULL COMMENT '邮箱',
  `emailVerified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邮箱是否验证；0=否=1是',
  `loginIp` varchar(64) NOT NULL DEFAULT '' COMMENT '最后登录ip',
  `createdTime` int(10) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `loginTime` int(10) NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='会员表';

DROP TABLE IF EXISTS `gv_vip_profile`;
CREATE TABLE `gv_vip_profile` (
  `id` int(10) unsigned NOT NULL COMMENT '用户ID',
  `truename` varchar(255) NOT NULL DEFAULT '' COMMENT '真实姓名',
  `idcard` varchar(24) NOT NULL DEFAULT '' COMMENT '身份证号码',
  `gender` enum('male','female','secret') NOT NULL DEFAULT 'secret' COMMENT '性别',
  `iam` varchar(255) NOT NULL DEFAULT '' COMMENT '我是谁',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `mobile` varchar(32) NOT NULL DEFAULT '' COMMENT '手机',
  `signature` text COMMENT '签名',
  `about` text COMMENT '自我介绍',
  `company` varchar(255) NOT NULL DEFAULT '' COMMENT '公司',
  `weixin` varchar(255) NOT NULL DEFAULT '' COMMENT '微信',
  `wechat_nickname` varchar(512) DEFAULT '' COMMENT '微信昵称',
  `wechat_picture` varchar(256) DEFAULT '' COMMENT '微信头像',
  `qq` varchar(16) NOT NULL DEFAULT '' COMMENT 'QQ',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC COMMENT='会员端用户资料表';
DROP TABLE IF EXISTS `gv_vip_bind`;
CREATE TABLE `gv_vip_bind`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户绑定ID',
  `type` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '用户绑定类型',
  `fromId` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '来源方用户ID',
  `toId` int(10) UNSIGNED NOT NULL COMMENT '被绑定的用户ID',
  `token` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT 'oauth token',
  `refreshToken` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT 'oauth refresh token',
  `expiredTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'token过期时间',
  `createdTime` int(10) UNSIGNED NOT NULL COMMENT '绑定时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `type`(`type`, `fromId`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '会员端用户关联绑定表';

DROP TABLE IF EXISTS `gv_vip_token`;
CREATE TABLE `gv_vip_token`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'TOKEN编号',
  `token` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'TOKEN值',
  `vipId` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKEN关联的用户ID',
  `type` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'TOKEN类型',
  `data` text CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'TOKEN数据',
  `times` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKEN的校验次数限制(0表示不限制)',
  `remainedTimes` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKE剩余校验次数',
  `expiredTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TOKEN过期时间',
  `createdTime` int(10) UNSIGNED NOT NULL COMMENT 'TOKEN创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `token`(`token`(60)) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Dynamic COMMENT = '会员用户token表';
SQL);
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
