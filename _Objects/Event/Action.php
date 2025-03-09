<?php
namespace Objects\Event;


class Action {
    public function __construct(
        private string $name,
        private string $componentName,
        private string $url,
        private string $displayName,
        private $execute
    ) {
    }

    public function getName() {
        return $this->name;
    }

    public function getUrl() {
        return $this->url;
    }

    public function getComponentName() {
        return $this->componentName;
    }

    public function getDisplay() {
        return $this->displayName;
    }

    public function getExecute() {
        return $this->execute;
    }
}