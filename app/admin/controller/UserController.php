<?php

namespace app\admin\controller;

use app\admin\BaseController;
use support\Request;

class UserController extends BaseController
{
    public function index(Request $request)
    {
        return response(__CLASS__);
    }


    public function getMenuAdmin(Request $request)
    {
        return $this->createSuccessJsonResponse(config('vue'), []);
    }
}
