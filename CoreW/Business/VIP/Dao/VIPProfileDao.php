<?php


namespace CoreW\Business\VIP\Dao;


use CoreW\Dao\GeneralDaoInterface;

interface VIPProfileDao extends GeneralDaoInterface
{
    public function findByIds(array $ids);

    public function findDistinctMobileProfiles($start, $limit);
}