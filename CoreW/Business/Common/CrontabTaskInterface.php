<?php

namespace CoreW\Business\Common;

interface CrontabTaskInterface
{
    public function execute() : void;
}