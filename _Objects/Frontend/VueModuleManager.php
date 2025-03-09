<?php

namespace Objects\Frontend;

use Angle\Engine\RouterEngine\Route;

/**
 * Modules can register to provide Vue-Modules for a single page. 
 * These are loaded by the frontend and rendered by the corresponding module.
 * 
 * Each module can contribute to a specific route, 
 * e.g. /server/:id loads a module that shows a list of backups on 
 * the server-page, provided by the BackupModule.
 * 
 */
class VueModuleManager {
    private static $modules = [];

    public static function registerModule(array $mod) {
        self::$modules = array_merge(self::$modules, $mod);
    }

    /**
     * match against route
     *
     * @param Route $route
     * @return array
     */
    public static function match(Route|bool $route): array {
        if (!$route)
            return [];
        $a = [];
        foreach (self::$modules as $mod) {
            if ($mod->getRoute() == $route->getUrl()) {
                $a[] = [
                    'name'      => $mod->getName(),
                    'component' => $mod->getComponentName(),
                    'handler'   => $mod->getHandler($route->getParameters()),
                    'display'   => $mod->getDisplay(),
                    'classes'   => $mod->getClasses()
                ];
            }
        }
        return $a;
    }
}
