<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;

class Setup {
    public static function startOnboarding() {


        Panel::compile(
            '_views/_pages/onboarding/start.html',
            []
        );
    }
}
