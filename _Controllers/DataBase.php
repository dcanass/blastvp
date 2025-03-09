<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 29.10.18
 * Time: 00:28
 */

namespace Controllers;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use PDO;
use PDOException;

class DataBase {
    private $pdo;
    /**
     * Logged queries.
     * @var array<array>
     */
    protected $log = [];


    /**
     * DataBase constructor.
     * @param $host
     * @param $user
     * @param $password
     * @param $database
     */
    public function __construct($host, $user, $password, $database) {
        Panel::setDatabase($this);
        try {
            $this->pdo = DriverManager::getConnection([
                'dbname'        => $database,
                'user'          => $user,
                'password'      => $password,
                'host'          => $host,
                'driver'        => 'pdo_mysql',
                'charset'       => 'utf8mb4',
                'driverOptions' => [
                        // PDO::MYSQL_ATTR_INIT_COMMAND => "SET CHARACTER SET utf8mb4, NAMES utf8mb4"
                    PDO::ATTR_PERSISTENT => true
                ]
            ]);

            // $this->pdo->exec("SET CHARACTER SET utf8mb4");
            $res = $this->pdo->query("SET CHARACTER SET utf8mb4, NAMES utf8mb4");
            $res->fetchAll();
        } catch (PDOException $e) {
            echo "Something went wrong while connecting to the Database. Below is the error, you can try to fix this by changing the values in the config.json file. <br /><br />";
            echo "error " . $e->getMessage();
            die();
        }
    }


    /**
     * custom query ,update,delete,insert,or fetch, joining multiple table etc, aritmathic etc
     * @param  string $sql custom query
     * @param  array $data associative array
     * @return bool|PDOResult recordset
     */
    public function custom_query($sql, $data = null, $types = null) {
        $params = [];
        $_types = [];
        if ($data) {
            foreach ($data as $k => $v) {
                if (is_array($v) && !$types) {
                    $params[] = $v;
                    $_types[] = ArrayParameterType::INTEGER;
                } else {
                    $params[] = $v;
                    $_types[] = $types[$k] ?? null;
                }
            }
        }
        $sel = $this->pdo->executeQuery($sql, $params, $_types);

        return (new PDOResult($sel));

    }

    /**
     * fetch only one row
     * @param  string $table table name
     * @param  string|array $col condition column
     * @param  string|array $val value column
     * @param  int $method the return method
     * @return false|object|array recordset
     */
    public function fetch_single_row($table, $col, $val, $method = PDO::FETCH_OBJ) {
        if (is_array($col)) {
            $col = implode(' = ? AND ', $col) . " = ?";
        } else {
            $col = "$col = ?";
        }
        if (!is_array($val)) {
            $val = array($val);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM `$table` WHERE $col");
        $res  = $stmt->execute($val);

        $a = new PDOResult($res);

        $res = $a->fetchAll($method);
        return isset($res[0]) ? $res[0] : false;
    }

    /**
     * fetch all data
     * @param  string $table table name
     * @return array
     */
    public function fetch_all($table) {
        $qb = $this->pdo->createQueryBuilder();
        return (new PDOResult($qb
            ->select('*')
            ->from("`$table`")
            ->executeQuery()))->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * count rows in a table
     *
     * @param string $table
     * @return int
     */
    public function countRows($table) {
        $qb     = $this->pdo->createQueryBuilder();
        $result = $qb
            ->select('*')
            ->from("`$table`")
            ->executeQuery();
        return $result->rowCount();
    }

    /**
     * fetch row with condition
     * @param  string $table table name
     * @param  array $col which columns name would be select
     * @param  array $where what column will be the condition
     * @return bool|PDOResult recordset
     */
    public function fetch_multi_row($table, $col, $where) {
        $data = array_values($where);
        //grab keys
        $cols  = array_keys($where);
        $colum = implode(', ', $col);
        foreach ($cols as $key) {
            $keys   = $key . "=?";
            $mark[] = $keys;
        }
        $jum = count($where);
        if ($jum > 1) {
            $im  = implode(' and ', $mark);
            $sel = $this->pdo->prepare("SELECT $colum from `$table` WHERE $im");
        } else {
            $im  = implode('', $mark);
            $sel = $this->pdo->prepare("SELECT $colum from `$table` WHERE $im");
        }
        $result = $sel->execute($data);
        return new PDOResult($result);
    }

    /**
     * fetch row with condition
     * @param  string $table table name
     * @param  array $col which columns name would be select
     * @param  array $where what column will be the condition
     * @param $order
     * @param  string $index based on which column
     * @return bool|PDOResult recordset
     */
    public function fetch_multi_row_order($table, $col, $where, $order, $index) {
        $data = array_values($where);
        //grab keys
        $cols  = array_keys($where);
        $colum = implode(', ', $col);
        foreach ($cols as $key) {
            $keys   = $key . "=?";
            $mark[] = $keys;
        }
        $jum = count($where);
        if ($jum > 1) {
            $im  = implode('? and  ', $mark);
            $sel = $this->pdo->prepare("SELECT $colum from `$table` WHERE $im ORDER BY $index $order");
        } else {
            $im  = implode('', $mark);
            $sel = $this->pdo->prepare("SELECT $colum from `$table` WHERE $im ORDER BY $index $order");
        }
        $a = $sel->execute($data);
        return new PDOResult($a);
    }

    /**
     * check if there is exist data
     * @param  string $table table name
     * @param  array $dat array list of data to find
     * @return true or false
     */
    public function check_exist($table, $dat) {
        $data = array_values($dat);
        //grab keys
        $cols = array_keys($dat);
        $col  = implode(', ', $cols);
        foreach ($cols as $key) {
            $keys   = $key . "=?";
            $mark[] = $keys;
        }
        $jum = count($dat);
        if ($jum > 1) {
            $im  = implode(' and  ', $mark);
            $sel = $this->pdo->prepare("SELECT $col from `$table` WHERE $im");
        } else {
            $im  = implode('', $mark);
            $sel = $this->pdo->prepare("SELECT $col from `$table` WHERE $im");
        }
        $a   = $sel->execute($data);
        $b   = new PDOResult($a);
        $jum = $b->rowCount();
        if ($jum > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * get last insert id
     * @return int last insert id
     */
    public function get_last_id() {
        return $this->pdo->lastInsertId();
    }

    /**
     * insert data to table
     * @param  string $table table name
     * @param  array $dat associative array 'column_name'=>'val'
     * @return int
     */
    public function insert($table, $dat) {
        $qb = $this->pdo->createQueryBuilder();
        $qb->insert("`$table`");

        foreach ($dat as $k => $v) {
            $qb->setValue("`$k`", ':' . $k);
            $qb->setParameter($k, $v);
        }

        return $qb->executeStatement();
    }

    /**
     * update record
     * @param  string $table table name
     * @param  array $dat associative array 'col'=>'val'
     * @param  string $id primary key column name
     * @param  int $val key value
     */
    public function update($table, $dat, $id, $val) {
        $qb = $this->pdo->createQueryBuilder();

        $qb->update("`$table`");
        foreach ($dat as $k => $v) {
            $qb->set($k, ":" . $k);
            $qb->setParameter($k, $v);
        }
        $qb->where($id . ' = :id')->setParameter('id', $val);
        return $qb->executeStatement();
    }

    /**
     * delete record
     * @param  string $table table name
     * @param  string $where column name for condition (commonly primay key column name)
     * @param  int $id key value
     */
    public function delete($table, $where, $id) {
        $qb = $this->pdo->createQueryBuilder();
        return $qb
            ->delete("`$table`")
            ->where($where . ' = ?')
            ->setParameter(0, $id)
            ->executeStatement();
    }

    public function __destruct() {
        $this->pdo = null;
    }
}