<?php
namespace Module\BaseModule\Controllers;

use Controllers\Panel;

class Internal {

    /**
     * function to compare the existing database with the database.sql file
     * to determine missing changes in the database.sql file
     * 
     * Steps:
     * 1. load database.sql into a new database called "syncro_test"
     * 2. load all tables using "show tables" from the syncro_test DB
     * 3. load schema definition with "describe <table>" for each table
     * 4. do 2 & 3 for actual Database
     * 5. generate readable diff
     * 6. make sure migrations table holds the same entries
     * 
     */
    public static function compareDatabaseSchema() {
        $file = file_get_contents(__DIR__ . "../../../../database.sql");
        $db   = Panel::getDatabase();
        $db->custom_query("DROP DATABASE IF EXISTS syncro_test");
        $db->custom_query("CREATE DATABASE syncro_test");
        $db->custom_query("USE syncro_test");
        $db->custom_query($file);

        $tables  = [];
        $_tables = $db->custom_query("SHOW TABLES")->fetchAll(\PDO::FETCH_OBJ);
        foreach ($_tables as $table) {
            $description                           = $db->custom_query("DESCRIBE `" . $table->Tables_in_syncro_test . '`')->fetchAll(\PDO::FETCH_OBJ);
            $tables[$table->Tables_in_syncro_test] = $description;
        }

        $originalTables = [];
        $db->custom_query("USE " . DB_NAME);
        $_tables = $db->custom_query("SHOW TABLES")->fetchAll(\PDO::FETCH_OBJ);
        foreach ($_tables as $table) {
            $description                                 = $db->custom_query("DESCRIBE `" . $table->Tables_in_proxmoxcp . '`')->fetchAll(\PDO::FETCH_OBJ);
            $originalTables[$table->Tables_in_proxmoxcp] = $description;
        }

        header('Content-Type: text/html');
        foreach ($tables as $shouldName => $shouldDefinition) {
            // find the table in the actual database
            $is = $originalTables[$shouldName];
            echo "Table: " . $shouldName . "<br>";
            foreach ($is as $field) {
                // dump($field);
                // check if field in sync DB has the same definition as in original
                $inSync = array_values(array_filter($shouldDefinition, fn($ele) => $ele->Field == $field->Field));
                if (!$inSync[0]) {
                    die('Failed to find field ' . $field->Field . ' in table ' . $shouldName);
                }
                $inSync = $inSync[0];
                foreach ($field as $k => $v) {
                    if ($inSync->$k != $v) {
                        dump($inSync, $v);
                        echo "Found field error:" . "<br>";
                        echo "Key: " . $k . "<br>";
                        echo "File Value: " . $v . "<br>";
                        echo "DB Value: " . $inSync->$k . "<br>";
                    }
                }
            }
        }
        die();
        // dump($_tables);
        // return $originalTables;
    }

}