<?php
namespace Objects\Permissions\Roles;
use Objects\Permissions\AbstractRole;

class SupporterRole extends AbstractRole {
    public function __construct() {
        parent::__construct(
            "supporter", 
            "the supporter can read all resources but can only change tickets"
        );
    }
}