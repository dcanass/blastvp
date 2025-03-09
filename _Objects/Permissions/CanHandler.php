<?php
namespace Objects\Permissions;

use Objects\Permissions\Roles\AdminRole;
use Objects\Permissions\Roles\SupporterRole;
use Objects\User;

class CanHandler {

    public function __construct(
        private readonly User $user
    ) {
    }

    /**
     * checks if the user has create permissions for a certain resource
     *
     * @param string $resource
     * @param integer $resourceId
     * @return boolean
     */
    public function create(
        string $resource,
        int $resourceId = 0
    ): bool {
        return $this->check("create", $resource, $resourceId);
    }

    /**
     * checks if the user has update permissions for a certain resource
     *
     * @param string $resource
     * @param integer $resourceId
     * @return boolean
     */
    public function update(
        string $resource,
        int $resourceId
    ): bool {
        return $this->check("update", $resource, $resourceId);
    }

    /**
     * checks if the user has read permissions for a certain resource
     *
     * @param string $resource
     * @param integer $resourceId
     * @return boolean
     */
    public function read(
        string $resource,
        int $resourceId
    ): bool {
        return $this->check("read", $resource, $resourceId);
    }

    /**
     * checks if the user has delete permissions for a certain resource
     *
     * @param string $resource
     * @param integer $resourceId
     * @return boolean
     */
    public function delete(
        string $resource,
        int $resourceId
    ): bool {
        return $this->check("delete", $resource, $resourceId);
    }

    /**
     * check if the user has permission for a certain action
     *
     * @param string $method the method, create, read, update, delete
     * @param string $resource the name of the resource
     * @param integer $id the id of the resource
     * @return boolean true if has access, false otherwise
     */
    private function check(string $method, string $resource, int $id): bool {
        // admins are allowed to do absolutely anything
        if ($this->user->getRole() == AdminRole::class) {
            return true;
        }

        // supporters can only create and read stuff
        if ($this->user->getRole() == SupporterRole::class && in_array($method[0], ['c', 'r'])) {
            return true;
        }

        //
        $resource     = new $resource();
        $resourceName = $resource->getName();
        // find in user permissions
        $permissions = $this->user->getACL();
        foreach ($permissions as $permission) {
            if (
                $permission->resource === $resourceName &&
                $permission->resourceId === $id &&
                in_array($method[0], str_split($permission->permissions))
            ) {
                return true;
            }
        }

        // last resort, call function to check if the user is the resource owner
        return $resource->check($this->user, $id, $method[0]);
    }
}