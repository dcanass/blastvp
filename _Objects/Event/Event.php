<?php

namespace Objects\Event;

class Event {

    private $name, $description, $parameters, $friendly;
    private $listeners = [];

    public function setName($name) {
        $this->name = $name;
        return $this;
    }

    public function setFriendlyName($s) {
        $this->friendly = $s;
        return $this;
    }

    public function setDescription($desc) {
        $this->description = $desc;
        return $this;
    }

    public function setParameters($parameters) {
        $this->parameters = $parameters;
        return $this;
    }

    public function addListener($func) {
        $this->listeners[] = $func;
    }

    public function getName() {
        return $this->name;
    }

    public function getDescription() {
        return $this->description;
    }

    public function getParameters() {
        return $this->parameters;
    }

    public function getListeners() {
        return $this->listeners;
    }

    public function getFriendlyName() {
        return $this->friendly;
    }
}