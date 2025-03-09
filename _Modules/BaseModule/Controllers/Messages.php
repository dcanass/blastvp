<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 27.02.19
 * Time: 15:10
 */

namespace Module\BaseModule\Controllers;


use Angle\Engine\Template\Engine;
use Controllers\Panel;

class Messages {

    public static function inbox() {
        Panel::compile("_views/_pages/account/messages.html");
    }
}
