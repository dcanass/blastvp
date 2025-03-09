<?php
namespace Module\BaseModule\Controllers\Admin;

use Controllers\Panel;
use Doctrine\DBAL\ParameterType;
use Module\BaseModule\BaseModule;
use Objects\Address;
use Objects\Formatters;
use Objects\Invoice;
use Objects\Permissions\ACL;
use Objects\Permissions\Resources\UserResource;
use Objects\Permissions\Roles\AdminRole;
use Objects\Ticket;
use Objects\User;

class UserAPI {


    /**
     * return all users
     *
     * @return array
     */
    public static function apiUsers() {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->read(UserResource::class, 0))
            return [
                'error' => true,
                'code'  => 401
            ];

        $map = function ($e) {
            unset($e['password']);
            unset($e['credential']);
            $e['registerFormatted'] = Formatters::formatDateAbsolute($e['register']);
            return $e;
        };
        return array_map($map, Panel::getDatabase()->fetch_all('users'));
    }

    public static function apiUser($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->read(UserResource::class, $id))
            return [
                'error' => true,
                'code'  => 401
            ];

        $u = (new User($id))->load();
        $a = (new Address($id))->load();

        return ['data' => [
            'id'             => $u->getId(),
            'name'           => $u->getName(),
            'email'          => $u->getEmail(),
            'balance'        => [
                'balance' => $u->getBalance()->getFormattedBalance()
            ],
            'register'       => $u->getRegister(),
            'role'           => $u->getRole(),
            'rank'           => $u->getRank(),
            'permission'     => $u->getPermission(),
            'activeProducts' => $u->getActiveProducts(),
            'acl'            => $u->getACL(),
            'servers'        => $u->getServers(),
            'address'        => $a,
            'profilePicture' => $u->getProfilePicture(),
            'twofaEnabled'   => $u->getTwofaEnabled()
        ]];
    }

    /**
     * disable two factor authentication for a user
     *  Admin API
     * 
     * @param int $userid
     * @return array
     */
    public static function disable2FA($userid) {
        $user = BaseModule::getUser();
        if ($user->getRole() !== AdminRole::class) {
            return [
                'code' => 403
            ];
        }

        Panel::getDatabase()->update('users', [
            'twofaSecret'  => null,
            'twofaEnabled' => null
        ], 'id', $userid);

        return [
            'error' => false
        ];
    }
}