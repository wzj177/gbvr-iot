<?php


namespace CoreW\Business\Product\Dao;


use CoreW\Dao\AdvancedDaoInterface;

interface TagDao extends AdvancedDaoInterface
{
    /**
     * @param $type
     * @param $userId
     * @param $names
     * @return array[]
     */
    public function getAllByTypeAndUserIdAndNames($type, $userId, $names): array;

    /**
     * @param array $ids
     * @return array
     */
    public function getAllByIds(array $ids): array;

    /**
     * @param array $names
     * @return array
     */
    public function getAllByNames(array $names): array;
}