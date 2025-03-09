<?php

namespace Migration;

abstract class MigrationInterface {

    /**
     * handle the up event of a migration, apply changes
     *
     * @return void|bool|\Controllers\PDOResult
     */
    abstract function up();

    /**
     * handle the down event of a migration, reverse changes
     *
     * @return void|bool|\Controllers\PDOResult
     */
    abstract function down();

    /**
     * return the name of the migration
     *
     * @return string
     */
    abstract function getName();
}