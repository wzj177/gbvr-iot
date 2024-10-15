<?php


namespace app\middleware\security\firewall;



use support\Request;

interface ListenerInterface
{

    public function handle(Request $request);
}