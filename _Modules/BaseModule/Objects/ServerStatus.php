<?php
namespace Module\BaseModule\Objects;

use Controllers\Panel;

class ServerStatus {
    static $ONLINE = "online";
    static $OFFLINE = "offline";
    static $STARTING = "starting";
    static $STOPPING = "stopping";
    static $RESTARTING = "restarting";
    static $SUSPENDED = "suspended";

    public static function getTextRepresentation($status) {
        return Panel::getLanguage()->get('global', 'm_' . $status);
    }
}