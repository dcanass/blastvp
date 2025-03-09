<?php
namespace Objects\Permissions;

abstract class AbstractRole {

    public function __construct(
        private readonly string $name, 
        private readonly string $desciption = ""
    ) {}
    
    public function getName() {
        return $this->name;
    }

    public function getDescription() {
        return $this->getDescription();
    }
}