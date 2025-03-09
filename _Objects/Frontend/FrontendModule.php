<?php

namespace Objects\Frontend;

class FrontendModule {

    private string $name;
    private string $route;
    private string $handler;
    private string $componentName;
    private string $display;
    private string $classes = '';

    public function __construct(string $moduleName) {
        $this->name = $moduleName;
    }


    /**
     * Get the value of route
     */
    public function getRoute() {
        return $this->route;
    }

    /**
     * Set the value of route
     *
     * @return  self
     */
    public function setRoute($route) {
        $this->route = $route;

        return $this;
    }

    /**
     * Get the value of name
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Set the value of name
     *
     * @return  self
     */
    public function setName($name) {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the value of handler
     *
     * replaces the parameters in the URL
     */
    public function getHandler($params) {
        $a = $this->handler;
        foreach ($params as $k => $v) {
            $a = str_replace(":" . $k, $v, $a);
        }

        return $a;
    }

    /**
     * Set the value of handler
     *
     * @return  self
     */
    public function setHandler($handler) {
        $this->handler = (constant("APP_URL") == "/" ? "" : constant("APP_URL")) . $handler;

        return $this;
    }

    /**
     * Get the value of componentName
     */
    public function getComponentName() {
        return $this->componentName ?? $this->name;
    }

    /**
     * Set the value of componentName
     *
     * @return  self
     */
    public function setComponentName($componentName) {
        $this->componentName = $componentName;

        return $this;
    }

    /**
     * Get the value of display
     */
    public function getDisplay() {
        return $this->display;
    }

    /**
     * Set the value of display
     *
     * @return  self
     */
    public function setDisplay($display) {
        $this->display = $display;

        return $this;
    }

    /**
     * Get the value of classes
     */
    public function getClasses() {
        return $this->classes;
    }

    /**
     * Set the value of classes
     *
     * @return  self
     */
    public function setClasses($classes) {
        $this->classes = $classes;

        return $this;
    }
}