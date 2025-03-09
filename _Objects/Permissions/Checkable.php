<?php
namespace Objects\Permissions;

interface Checkable {

    /**
     * implement the function that checks if a user has access to the requested
     * resource. This might call the database for more information, do some stuff
     *
     * @param \Objects\User $user the user that is requesting
     * @param integer $resourceId the ID of the resource that is being requested
     * @param string $action the action the user wants to perform
     * @return boolean true if the user has access, false if he has not
     */
    public function check(\Objects\User $user, int $resourceId, string $action): bool;

    public static function getName(): string;
}