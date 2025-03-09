<?php
namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Doctrine\DBAL\ArrayParameterType;
use Module\BaseModule\BaseModule;
use Objects\Permissions\ACL;
use Objects\Permissions\Resources\ServerResource;
use Objects\Permissions\Resources\UserResource;

class Tags {

    public static function apiGet($resource) {
        $user = BaseModule::getUser();
        
        list($resourceEntity, $resourceId) = explode('-', $resource);

        $resourceEntity = match ($resourceEntity) {
            'server' => ServerResource::class,
            'user' => UserResource::class,
        };

        if (!ACL::can($user)->read($resourceEntity, $resourceId)) {
            return [
                'code' => 403
            ];
        }

        return self::internalGet($resource);
    }

    public static function apiPost($resource) {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();

        $tags = $b['tags'] ?? [];

        list($resourceEntity, $resourceId) = explode('-', $resource);

        $resourceEntity = match ($resourceEntity) {
            'server' => ServerResource::class,
            'user' => UserResource::class,
        };

        if (!ACL::can($user)->delete($resourceEntity, $resourceId)) {
            return [
                'code' => 403
            ];
        }

        foreach ($tags as $tag) {
            $key   = $tag['key'];
            $value = $tag['value'];

            $ex = Panel::getDatabase()->check_exist('tags', ['`key`' => $key, '`resource`' => $resource]);
            if ($ex) {
                Panel::getDatabase()->custom_query("UPDATE tags SET `value`=? WHERE `resource`=?", [
                    'value'    => $value,
                    'resource' => $resource
                ]);
            } else {
                Panel::getDatabase()->insert("tags", [
                    'resource' => $resource,
                    'key'      => $key,
                    'value'    => $value,
                ]);
            }

        }
        if (sizeof($tags) == 0) {
            Panel::getDatabase()->custom_query("DELETE FROM `tags` WHERE `resource`=?", [
                'resource' => $resource
            ]);
        } else {
            // pruge everthing else
            Panel::getDatabase()->custom_query("DELETE FROM `tags` WHERE `key` NOT IN (?) AND `resource`=?", [
                array_map(fn($e)      => $e['key'], $tags),
                $resource,
            ], [ArrayParameterType::STRING]);
        }

        return array_map(fn($e) => $e['key'], $tags);
    }

    /**
     * internal get
     *
     * @param string $resource
     * @return array
     */
    public static function internalGet($resource) {
        return Panel::getDatabase()->custom_query("SELECT * FROM tags WHERE `resource`=?", ['resource' => $resource])->fetchAll(\PDO::FETCH_ASSOC);
    }
}