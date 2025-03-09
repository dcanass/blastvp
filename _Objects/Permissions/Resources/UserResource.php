<?php

namespace Objects\Permissions\Resources;

use Controllers\Panel;
use Objects\Permissions\Checkable;
use Objects\Permissions\Roles\AdminRole;
use Objects\Permissions\Roles\SupporterRole;

class UserResource implements Checkable {

    public function check(\Objects\User $user, int $resourceId, string $action): bool {
        if (in_array($user->getRole(), [
            AdminRole::class, SupporterRole::class
        ]) && $resourceId == 0) {
            if ($action == "r") {
                return true;
            }
        }

        return false;
    }

    public static function getName(): string {
        return "server";
    }
}