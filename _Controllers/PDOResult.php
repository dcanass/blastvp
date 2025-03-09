<?php
namespace Controllers;

use Doctrine\DBAL\Result;

/**
 * A wrapper class to ensure functionality for the custom_query function the DataBase.php
 */
class PDOResult {

    function __construct(
        private readonly Result $result
    ) {
    }


    /**
     * fetch all data as something
     *
     * @param int $method
     * @return false|array
     */
    public function fetchAll($method = \PDO::FETCH_OBJ) {
        //
        if ($method == \PDO::FETCH_OBJ) {
            return array_map(fn($e) => (object) $e, $this->result->fetchAll());
        }

        if ($method == \PDO::FETCH_ASSOC) {
            return $this->result->fetchAll();
        }

        return false;
    }

    /**
     * return the row count of the query
     *
     * @return integer
     */
    public function rowCount(): int {
        return $this->result->rowCount();
    }
}