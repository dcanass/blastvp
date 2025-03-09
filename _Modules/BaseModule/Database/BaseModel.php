<?php

namespace Module\BaseModule\Database;

use Controllers\Panel;
use Exception;

abstract class BaseModel {
    protected array $attributes = [];
    protected string $table;
    protected string $primaryKey = 'id';
    protected bool $isNew = true;

    public static function fromArray(array $data = []) {
        return new static($data);
    }

    public static function fromId($id) {
        $self       = (new static);
        $table      = $self->table;
        $primaryKey = $self->primaryKey;

        $query = Panel::getDatabase()->fetch_single_row($table, $primaryKey, $id, \PDO::FETCH_ASSOC);

        return new static($query);
    }

    public function __construct(array $data = []) {
        $this->fill($data);
        if (isset($data[$this->primaryKey])) {
            $this->isNew = false;
        }
    }

    // Magic getter for dynamic properties
    public function __get(string $name) {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }
        throw new Exception("Property {$name} does not exist.");
    }

    // Magic setter for dynamic properties
    public function __set(string $name, $value) {
        $this->attributes[$name] = $value;
    }

    public function toArray(): array {
        $data = $this->attributes;

        if (method_exists($this, 'extendArray')) {
            $data = array_merge($data, $this->extendArray());
        }

        return $data;
    }

    // Fill attributes dynamically
    public function fill(array $data): void {
        foreach ($data as $key => $value) {
            $this->__set($key, $value);
        }
    }

    /**
     * updates or inserts a new record
     * 
     * @return bool|int
     */
    public function save(): bool {
        if ($this->isNew) {
            return $this->insert();
        } else {
            return $this->update();
        }
    }

    /**
     * insert a new record into the database
     * 
     * @return int
     */
    protected function insert(): bool {
        $id = Panel::getDatabase()->insert($this->table, $this->attributes);
        return Panel::getDatabase()->get_last_id();
    }

    /**
     * update an existing record in the database
     * 
     * @return bool
     */
    protected function update(): bool {
        $res = Panel::getDatabase()->update(
            $this->table,
            $this->attributes,
            $this->primaryKey,
            $this->attributes[$this->primaryKey]
        );
        return $res;
    }

    public function remove(): bool {
        $res = Panel::getDatabase()->delete($this->table, $this->primaryKey, $this->attributes[$this->primaryKey]);
        return $res;
    }
}