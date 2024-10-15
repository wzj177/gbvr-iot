<?php

namespace CoreW\Business\VIP\Dao;

interface VIPBindDao
{
    public function getByFromId($fromId);

    public function getByTypeAndFromId($type, $fromId);

    public function getByToIdAndType($type, $toId);

    public function getByToken($token);

    public function findByToId($toId);

    public function findByToIdAndType($type, $toId);

    public function deleteByTypeAndToId($type, $toId);

    public function deleteByToId($toId);

    public function findByTypeAndFromIds($type, $fromIds);

    public function findByTypeAndToIds($type, $toIds);
}