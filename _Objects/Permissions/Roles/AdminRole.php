<?php
namespace Objects\Permissions\Roles;
use Objects\Permissions\AbstractRole;

class AdminRole extends AbstractRole {
    public function __construct() {
        parent::__construct(
            "admin", 
            "the administrator of the panel"
        );
    }
}