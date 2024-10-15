<?php


namespace CoreW\Dao;


interface SerializerInterface
{
    public function serialize($method, $value);

    public function unserialize($method, $value);
}