<?php

namespace app\admin\controller;

use support\Request;

class ProductController
{
    public function index(Request $request)
    {
        return response(__CLASS__);
    }
}
