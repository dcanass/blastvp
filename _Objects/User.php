<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 15.02.19
 * Time: 14:23
 */

namespace Objects;

use Controllers\Panel;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Module\BaseModule\Controllers\Order;
use Module\BaseModule\Objects\ServerStatus;
use Objects\Permissions\Roles\AdminRole;
use Objects\Permissions\Roles\CustomerRole;
use Objects\Permissions\Roles\SupporterRole;

class User {

    public $id;
    public $name;
    public $email;
    public $permission;
    public $register;
    public $limit;
    public $balance;
    public $notifications;
    public $profilePicture;
    public $confirmationToken;
    public $twofaEnabled;
    public $twofaSecret;
    public $resetToken;

    public $acl;

    public $apiToken = null;

    public $activeProducts = 0;

    public function __construct($id, $name = "", $email = "", $permission = 1, $limit = 0) {
        $this->id         = $id;
        $this->name       = $name;
        $this->email      = $email;
        $this->permission = $permission;
        $this->limit      = $limit;
    }

    public function load() {
        $db = Panel::getDatabase();

        if (Panel::getDatabase()->custom_query("SHOW TABLES LIKE 'api-tokens'")->rowCount() > 0) {
            $user = $db->custom_query(<<<SQL
                SELECT 
                    * 
                FROM 
                    users
                LEFT JOIN 
                    balances 
                ON 
                    users.id = balances.userid
                LEFT JOIN
                    `api-tokens`
                ON 
                    users.id = `api-tokens`.userId
                WHERE users.id=?
            SQL, ['id' => $this->id])->fetchAll();
        } else {
            $user = $db->custom_query(<<<SQL
            SELECT 
                * 
            FROM 
                users
            LEFT JOIN 
                balances 
            ON 
                users.id = balances.userid
            WHERE users.id=?
        SQL, ['id' => $this->id])->fetchAll();
        }

        if (!isset($user[0])) {
            return false;
        }
        $user = $user[0];

        $this->loadNotifications();
        $this->name           = $user->username;
        $this->email          = $user->email;
        $this->permission     = $user->permission;
        $this->register       = $user->register;
        $this->balance        = new Balance($user->balance, $this->id);
        $this->apiToken       = $user->token ?? "";
        $this->profilePicture = $this->getProfilePicture();
        $this->twofaEnabled   = $user->twofaEnabled;
        $this->twofaSecret    = $user->twofaSecret;
        $this->resetToken     = $user->resetToken;

        $this->loadPermissions();

        return $this;
    }

    /**
     * @return string
     */
    public function getRank(): string {
        return Formatters::getRank($this->permission);
    }

    /**
     * @return mixed
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id) {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name) {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getEmail(): string {
        return $this->email;
    }

    /**
     * @param string $email
     */
    public function setEmail(string $email) {
        $this->email = $email;
    }

    /**
     * @return int
     */
    public function getPermission(): int {
        return $this->permission;
    }

    public function getRole(): string {
        switch ($this->getPermission()) {
            case 1:
                return CustomerRole::class;
            case 2:
                return SupporterRole::class;
            case 3:
                return AdminRole::class;
        }

        return "";
    }

    /**
     * @param int $permission
     */
    public function setPermission(int $permission) {
        $this->permission = $permission;
    }

    /**
     * @return mixed
     */
    public function getRegister() {
        return $this->register;
    }

    /**
     * @param mixed $register
     */
    public function setRegister($register) {
        $this->register = $register;
    }

    /**
     * @return mixed
     */
    public function getLimit() {
        return $this->limit;
    }

    /**
     * @param mixed $limit
     */
    public function setLimit($limit) {
        $this->limit = $limit;
    }

    /**
     * Undocumented function
     *
     * @return Balance
     */
    public function getBalance() {
        return $this->balance;
    }

    public function getAddress() {
        return new Address($this->id);
    }

    public function getActiveProducts() {
        return $this->activeProducts;
    }

    public function loadNotifications() {
        $db = Panel::getDatabase();
        // check if table exists
        $tableExists   = $db->custom_query("SHOW TABLES LIKE 'notifications_settings'")->rowCount();
        $notifications = [];
        if ($tableExists > 0) {
            $notifications = $db->custom_query("SELECT * FROM notifications_settings WHERE userId=?", ['userId' => $this->id])->fetchAll(\PDO::FETCH_OBJ);
        }
        $temp = [];
        foreach ($notifications as $ele) {
            $temp = array_merge($temp, [$ele->type => $ele->enabled]);
        }
        $this->notifications = $temp;
        return $this;
    }

    public function hasNotificationsEnabled($type) {
        if (isset($this->notifications[$type])) {
            return (bool) $this->notifications[$type];
        } else {
            return false;
        }
    }

    public function getServers() {
        $db      = Panel::getDatabase();
        $servers = $db->custom_query(<<<SQL
            SELECT
                s.*,
                true as isShared
            FROM
                permissions p
            RIGHT JOIN servers s ON
                p.resourceId = s.id
            WHERE
                p.userId = ? AND p.resource = "server" AND 
                s.deletedAt IS NULL
            UNION
            SELECT
                s.*,
                false as isShared
            FROM
                servers s
            WHERE
                s.userId = ? AND 
                s.deletedAt IS NULL;
        SQL, [$this->id, $this->id]);
        $servers = array_map(function ($s) {
            $s = new Server($s);
            return $s->serialize();
        }, $servers->fetchAll(\PDO::FETCH_OBJ));
        return $servers;
    }

    public function loadPermissions() {
        try {
            $sql       = <<<SQL
            SELECT * FROM `permissions` WHERE userId=?
        SQL;
            $result    = Panel::getDatabase()->custom_query($sql, ['id' => $this->id])->fetchAll(\PDO::FETCH_OBJ);
            $this->acl = $result;
        } catch (TableNotFoundException $e) {
            $this->acl = [];
        }
        return $this;
    }

    public function getACL() {
        return $this->acl;
    }

    public function getProfilePicture() {
        switch (Settings::getConfigEntry('PROFILE_PICTURE_PROVIDER', 'Gravatar')) {
            case "Static":
                if (file_exists(__DIR__ . "/../_views/images/profile-overwrite.png")) {
                    return APP_URL . "images/profile-overwrite.png";
                }
                return APP_URL . "images/profile-picture.png";
            case "Gravatar":
                return "https://www.gravatar.com/avatar/" . md5($this->getEmail());
            case "Robohash":
                return "https://robohash.org/" . md5($this->getId() . $this->getEmail());
            case "Avatar":
                return "https://avatar.vercel.sh/" . md5($this->getId() . $this->getEmail());
        }
    }

    public function toArray() {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'permission'        => $this->permission,
            'register'          => $this->register,
            'balance'           => $this->balance->getBalance(),
            'notifications'     => $this->notifications,
            'profilePicture'    => $this->profilePicture,
            'confirmationToken' => $this->confirmationToken
        ];
    }


    /**
     * Get the value of twofaEnabled
     */
    public function getTwofaEnabled() {
        return $this->twofaEnabled;
    }

    /**
     * Set the value of twofaEnabled
     *
     * @return  self
     */
    public function setTwofaEnabled($twofaEnabled) {
        $this->twofaEnabled = $twofaEnabled;

        return $this;
    }

    /**
     * Get the value of twofaSecret
     */
    public function getTwofaSecret() {
        return $this->twofaSecret;
    }

    /**
     * Set the value of twofaSecret
     *
     * @return  self
     */
    public function setTwofaSecret($twofaSecret) {
        $this->twofaSecret = $twofaSecret;

        return $this;
    }

    /**
     * return the api token of the user
     *
     * @return string
     */
    public function getApiToken() {
        return $this->apiToken;
    }

    public function getResetToken() {
        return $this->resetToken;
    }
}