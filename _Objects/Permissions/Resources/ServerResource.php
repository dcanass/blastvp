<?php

namespace Objects\Permissions\Resources;

use Controllers\Panel;
use Objects\Permissions\Checkable;

class ServerResource implements Checkable {

    public function check(\Objects\User $user, int $resourceId, string $action): bool {
        $server = Panel::getDatabase()->fetch_single_row('servers', ['id'], [$resourceId]);
        if (!$server)
            return false;
        return $server->userid == $user->getId();
    }

    public static function getName(): string {
        return "server";
    }
}