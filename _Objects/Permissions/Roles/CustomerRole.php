<?php
namespace Objects\Permissions\Roles;
use Objects\Permissions\AbstractRole;

class CustomerRole extends AbstractRole {
    public function __construct() {
        parent::__construct(
            "customer", 
            "the can only read and change all of his resources"
        );
    }
}