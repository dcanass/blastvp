<?php
namespace Objects\Event;

class Enricher {


    public function __construct(
        private string $name,
        private string $table,
        private array $fields
    ) {
    }

    /**
     * return the name of the enricher
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * return the table the enricher uses
     *
     * @return string
     */
    public function getTable(): string {
        return $this->table;
    }

    /**
     * return list of fields that the enricher provides
     *
     * @return array
     */
    public function getFields(): array {
        return $this->fields;
    }
}
