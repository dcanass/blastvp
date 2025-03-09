<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 02.11.18
 * Time: 09:45
 */

namespace Module;

use Angle\Engine\RouterEngine\Route;
use Controllers\Panel;
use Objects\NavPoint;
use stdClass;


abstract class Module {
    public $engine, $collection;
    /** @var string Name of the Module */
    private $name;
    /** @var string Version of the Module */
    private $version;
    /** @var string Author of the Module */
    private $author;
    private $routes, $config;
    private $sidebar = array(), $sidebartitle, $sidebaricon;
    private $sidebarAdmin = [], $sidebarAdminTitle, $sidebarAdminIcon;
    private $meta;


    /**
     * Module constructor.
     * @param $name
     * @param $version
     * @param $author
     */
    public function __construct($name, $author) {
        $this->name       = $name;
        $this->author     = $author;
        $this->engine     = Panel::getEngine();
        $this->collection = Panel::getCollection();
        $this->config     = file_get_contents(dirname(__FILE__) . "/" . $this->name . "/module.json");
        $this->meta       = json_decode(file_get_contents(dirname(__FILE__) . "/" . $this->name . "/module_meta.json")) ?? new stdClass();

        $this->version = $this->meta->version;
    }

    public function _registerRoutes($routes) {
        $this->routes = $routes;
        $this->register();
    }

    private function register() {
        foreach ($this->routes as $route) {
            $this->collection->attachRoute(new Route($route[0], array("_controller" => "Module\\" . $route[1], "parameters" => $route[2], "methods" => $route[3])));
        }
    }

    public function _registerSidebar($sidebartitle, $icon, $data) {
        $this->sidebaricon  = $icon;
        $this->sidebartitle = $sidebartitle;
        $this->sidebar      = $data;
    }


    public function _registerSidebarAdmin($sidebartitle, $icon, $data) {
        $this->sidebarAdminTitle = $sidebartitle;
        $this->sidebarAdminIcon  = $icon;
        $this->sidebarAdmin      = $data;
    }

    public function _registerInterceptors($data) {
        Panel::addToInterceptors($data);
    }


    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getVersion(): string {
        return $this->version;
    }

    /**
     * @return string
     */
    public function getAuthor(): string {
        return $this->author;
    }

    public function getSidebarTitle() {
        return $this->sidebartitle;
    }

    public function getSidebar() {
        return $this->sidebar;
    }

    public function getSidebaricon() {
        return $this->sidebaricon;
    }

    public function getSidebarAdminTitle() {
        return $this->sidebarAdminTitle;
    }

    public function getSidebarAdminIcon() {
        return $this->sidebarAdminIcon;
    }

    public function getAdminSidebar() {
        return $this->sidebarAdmin;
    }

    public function getMeta(): mixed {
        return $this->meta;
    }
}