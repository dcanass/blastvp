<?php
use Controllers\Panel;
use Controllers\Logger;
use Tracy\Debugger;

if (!file_exists("vendor/autoload.php")) {
    die("composer dependencies are not installed. Please execute the bin/install.sh script before!");
}
require("vendor/autoload.php");

new Controllers\ConfigValidator();

$debug = defined("DEBUG");
if (!$debug) {
    define("DEBUG", false);
}
Debugger::enable(!DEBUG);
Debugger::setLogger(new Logger);

$p = new Panel();
$p->match();
