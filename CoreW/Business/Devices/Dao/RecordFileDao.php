<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\DaoInterface;

interface RecordFileDao extends DaoInterface
{
    public function getByMainId(string $mainId) : ?array;

    public function countByDate(string $date) : int;
}
