<?php


namespace CoreW\Business\VIP\Dao\Impl;


use CoreW\Business\VIP\Dao\VIPProfileDao;
use CoreW\Dao\GeneralDaoImpl;

class VIPProfileDaoImpl extends GeneralDaoImpl implements VIPProfileDao
{
    protected $table = 'gv_vip_profile';

    public function findByIds(array $ids)
    {
        return $this->findInField('id', $ids);
    }

    public function findUserLikeTruename($truename)
    {
        if (empty($truename)) {
            $truename = '';
        }
        $truename = '%' . $truename . '%';
        $sql = "SELECT * FROM {$this->table} WHERE truename LIKE ?";

        return $this->db()->fetchAll($sql, [$truename]);
    }


    public function findDistinctMobileProfiles($start, $limit)
    {
        $sql = "SELECT * FROM {$this->table} WHERE `mobile` <> '' GROUP BY `mobile` ORDER BY `id` ASC";
        $sql = $this->sql($sql, [], $start, $limit);

        return $this->db()->fetchAll($sql);
    }

    protected function createQueryBuilder($conditions)
    {
        if (isset($conditions['mobile'])) {
            $conditions['mobile'] = "%{$conditions['mobile']}%";
        }

        if (isset($conditions['qq'])) {
            $conditions['qq'] = "{$conditions['qq']}%";
        }

        if (isset($conditions['keywordType']) && isset($conditions['keyword']) && 'truename' == $conditions['keywordType']) {
            $conditions['truename'] = "%{$conditions['keyword']}%";
        }

        if (isset($conditions['keywordType']) && isset($conditions['keyword']) && 'idcardLike' == $conditions['keywordType']) {
            $conditions['idcardLike'] = "%{$conditions['keyword']}%";
        }

        if (isset($conditions['idcard'])) {
            $conditions['idcard'] = trim($conditions['idcard']);
        }

        return parent::createQueryBuilder($conditions);
    }

    public function declares() : array
    {
        return [
            'orderbys'   => ['id'],
            'conditions' => [
                'mobile LIKE :mobile',
                'truename LIKE :truename',
                'idcard LIKE :idcardLike',
                'idcard = :idcard',
                'id IN (:ids)',
                'mobile = :tel',
                'mobile <> :mobileNotEqual',
                'qq LIKE :qq',
            ],
        ];
    }
}