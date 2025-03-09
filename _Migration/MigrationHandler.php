<?php

namespace Migration;

use Controllers\Logger;
use Controllers\Panel;

class MigrationHandler {

    static function getInstance() {
        return new self();
    }

    /**
     * return the current status of all migrations.
     * 
     *
     * @return array
     */
    public function checkMigrationStatus() {
        $applied = $this->getAppliedMigrations();
        return $applied;
    }

    public function getNewMigrations() {
        $applied = $this->getAppliedMigrations();
        $applied = array_values(array_map(function ($ap) {
            return $ap['name'];
        }, $applied));
        $exist   = $this->loadMigrations();
        $new     = [];
        foreach ($exist as $ex) {
            if (!in_array($ex, $applied)) {
                $new[] = $ex;
            }
        }
        return $new;
    }

    public function applyMigrations() {
        $results = [];
        foreach ($this->getNewMigrations() as $migration) {
            // load class and execute up function
            $class = $this->getClass($migration);
            if ($class) {
                $migName = $class->getName();
                try {
                    $result = $class->up();
                } catch (\Exception $e) {
                    dump($e);
                    $result = false;
                }
                if ($result) {
                    Logger::logIfCli("Applied migration: " . $migName);
                    Panel::getDatabase()->insert('migrations', [
                        'status' => 1,
                        'name'   => $migName,
                    ]);
                    $results[] = [
                        'name'   => $migName,
                        'status' => "OK"
                    ];
                } else {
                    Logger::logIfCli("Failed to apply migration: " . $migName);
                    $results[] = [
                        'name'   => $migName,
                        'status' => "ERR"
                    ];
                }
            }
        }
        Logger::logIfCli("Done applying all migrations");
        return [
            'result'  => true,
            'results' => $results
        ];
    }

    /**
     * generate class name for the dynamic migration class
     *
     * @param [type] $migration
     */
    private function getClass($migration) {
        $dynamicClass = __NAMESPACE__ . 's\\' . $migration;
        $dynamicClass = new $dynamicClass();
        return $dynamicClass;
    }

    /**
     * load all available migrations
     *
     * @return array
     */
    public function loadMigrations() {
        $items =
            array_merge(
                array_diff(scandir(__DIR__ . "/../migrations"), ['..', '.', 'all-migrations.php']),
                // load modules migration
                $this->getModuleMigrations()
            );

        usort($items, function ($a, $b) {
            $prefixA = explode('_', $a)[0];
            $prefixB = explode('_', $b)[0];
            return $prefixA - $prefixB;
        });

        $arr = array_map(function ($item) {
            return preg_replace('/^\d+_/', '', basename($item, '.php'));
        }, $items);
        return $arr;
    }

    /**
     * returns list of migrations from all Modules
     *
     * @return array
     */
    private function getModuleMigrations() {
        $l = glob(__DIR__ . '/../_Modules/*/_migrations/*.php');
        $l = array_map(function ($ele) {
            $a = explode("/", $ele);
            return end($a);
        }, $l);
        return $l;
    }

    /**
     * returns all applied migrations
     *
     * @return array
     */
    public function getAppliedMigrations() {
        $checkExists = Panel::getDatabase()->custom_query("SHOW TABLES LIKE 'migrations'")->rowCount();
        if ($checkExists == 0) {
            return [];
        }
        $applied = Panel::getDatabase()->custom_query("SELECT * FROM migrations WHERE `status` = 1")->fetchAll(\PDO::FETCH_ASSOC);

        return $applied;
    }

    /**
     * make sure that the needed migrations are installed.
     *
     * @param array $migs
     * @return boolean
     */
    public function ensureMigrations(array $migs) {
        $size = sizeof($migs);
        $res  = Panel::getDatabase()->custom_query("SELECT * FROM migrations WHERE `status` = 1 AND `name` IN (?)", ['data' => implode(",", $migs)])->rowCount();

        return $res == $size;
    }
}