<?php

namespace Module\BaseModule\Controllers;

use Module\BaseModule\BaseModule;
use Module\TrafficModule\Events\ServerEvents;
use Module\WebspaceModule\Helpers\CPanelHelper;
use Module\WebspaceModule\Helpers\WebuzoHelper;
use Objects\User;
use PhpParser\Error;

class Test
{
    public static function pr()
    {
        // ServerEvents::extendServer(['id' => 2]);
        die('ok');
    }

    public static function execute($id)
    {
    }
}
