<?php
namespace Objects\Permissions;

/**
 * syntax goal:
 * 
 * ACL::can|cannot($user)->create|modify|delete('resource', 10)
 * 
 * e.g:
 * 
 * ACL::can($user)->modify('server', 10)
 * 
 * permissions table:
 * id | userId | permissions | resource     | resourceId
 * 1    2        crud        server         10
 */
class ACL {
    private static $instance;

    static $ROLES = [
        'r'    => "Reader",
        'ru'   => "Editor",
        'rud'  => "Admin",
        'crud' => "Owner"
    ];

    public static function can(\Objects\User $user) {
        return new CanHandler($user);
    }

    /**
     * return ACL instance
     *
     * @return \Objects\Permissions\ACL acl instance
     */
    public static function getInstance(): ACL {
        if (!self::$instance) {
            self::$instance = new ACL();
        }
        return self::$instance;
    }
}