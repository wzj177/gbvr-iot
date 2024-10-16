<?php

namespace CoreW\Business\SystemLog\Service\Impl;

use CoreW\Business\BaseService;

use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\SystemLog\Dao\SystemLogDao;
use CoreW\Business\User\Service\UserService;
use support\utils\ArrayToolkit;

class SystemLogServiceImpl extends BaseService implements SystemLogService
{

    public function getLogById($id)
    {
        return $this->getSystemLogDao()->get($id);
    }

    public function info($module, $action, $message, array $params = null)
    {
        return $this->addLog('info', $module, $action, $message, $params);
    }

    public function warning($module, $action, $message, array $params = null)
    {
        return $this->addLog('warning', $module, $action, $message, $params);
    }

    public function error($module, $action, $message, array $params = null)
    {
        return $this->addLog('error', $module, $action, $message, $params);
    }

    public function countLogs($conditions)
    {
        return $this->getSystemLogDao()->count($conditions);
    }

    /**
     * @param $ids
     * @return bool
     */
    public function batchDelete($ids)
    {
        if ( empty($ids)) {
            return false;
        }

        return $this->getSystemLogDao()->batchDelete(['ids' => $ids]);
    }

    /**
     * @return array
     */
    public function getModuleOptions()
    {
        return ArrayToolkit::enumToList(LogEnum::getModuleItems());
    }


    /**
     * @param $module
     * @return array
     */
    public function getModuleActionOptions($module)
    {
        return ArrayToolkit::enumToList(LogEnum::getModuleActionItems($module));
    }

    public function searchLogs($conditions, $sort, $start, $limit, array $columns = [])
    {
        if ($start > 500 && isset($sort['id'])) {
            $inlogs = $this->getSystemLogDao()->search($conditions, $sort, $start, 1, ['id']);
            if (!empty($inlogs)) {
                $key = 'ASC' === strtoupper($sort['id']) ? 'id_GE' : 'id_LE';
                $last_id = $inlogs[0]['id'];
                $conditions[$key] = $last_id;
                $start = 0;
            }
        }

        if (!empty($conditions['userName'])) {
            $searchUsers = $this->getUserService()->searchUsers(['nickname' => $conditions['userName']], [], 0, PHP_INT_MAX, ['id']);
            $userIds = ArrayToolkit::column($searchUsers, 'id');
            $conditions['userIds'] = $userIds ? $userIds : [-1];
        }

        $logs = $this->getSystemLogDao()->search($conditions, $sort, $start, $limit, $columns);
        $userIds = ArrayToolkit::column($logs, 'userId');
        $users = $this->getUserService()->searchUsers(['ids' => $userIds], [], 0, PHP_INT_MAX, ['id', 'nickname']);
        $users = ArrayToolkit::index($users, 'id');
        foreach ($logs as &$log) {
            $log['userName'] = isset($log['userId']) && isset($users[$log['userId']]) ? $users[$log['userId']]['nickname'] : '';
        }

        return $logs;
    }

    protected function addLog($level, $module, $action, $message, array $params = null)
    {
        $fields = [
            'module' => $module,
            'action' => $action,
            'message' => $message,
            'data' => empty($params) ? '' : (is_string($params) ? $params : json_encode($params)),
            'userId' => !empty($params['userId']) ? $params['userId'] : 1,
            'ip' => !empty($params['currentIp']) ? $params['currentIp'] : '127.0.0.1',
            'createdTime' => time(),
            'level' => $level,
            'side' => \config('app.id', 'common')
        ];
        if (!empty($fields['ip']) && !\is_local_client($fields['ip'])) {
            $areaInfo = $this->bfw->offsetGet('ip2region')->parseIpToArea($fields['ip']);
            if (!empty($areaInfo)) {
                $fields['ipArea'] = $areaInfo;
            }
        }

        return $this->getSystemLogDao()->create($fields);
    }

    /**
     * @return SystemLogDao
     */
    protected function getSystemLogDao()
    {
        return $this->createDao('SystemLog:SystemLogDao');
    }

    /**
     * @return UserService
     */
    protected function getUserService()
    {
        return $this->createService('User:UserService');
    }
}
