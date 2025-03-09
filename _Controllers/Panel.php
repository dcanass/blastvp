<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 23.10.18
 * Time: 22:56
 */

namespace Controllers;


use Angle\Engine\RouterEngine\Collection;
use Angle\Engine\RouterEngine\Router;
use Angle\Engine\Template\Engine;
use GuzzleHttp\Client;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\UserModule\UserModule;
use Objects\Frontend\VueModuleManager;
use ProxmoxVE\Proxmox;

class Panel {

    private $engine, $collection, $database, $navmanager, $permissionmanager, $languageManager;


    public static $VERSION = "";

    private static $mailhelper;
    private static $proxmox;

    public $interceptors = [];
    public $curroute;
    public static $instance;
    public $content;

    public $template_file;
    public $router, $route;
    public $params = [];

    public $modules = [];

    /**
     * Panel constructor.
     */
    public function __construct() {
        self::setInstance($this);

        new LanguageLoader();
        new DataBase(Settings::getConfigEntry("DB_HOST"), Settings::getConfigEntry("DB_USER"), Settings::getConfigEntry("DB_PASSWORD"), Settings::getConfigEntry("DB_NAME"));

        $this->engine     = new Engine("_views");
        $this->collection = new Collection();

        $this->modules = (new ModuleLoader())->loadAll();

        self::$VERSION = self::getModule('BaseModule')->getMeta()->version;
        // execute afterInit() function in all modules if they have one 
        foreach ($this->modules as $module) {
            if (method_exists($module, "afterInit") && php_sapi_name() !== 'cli') {
                $module->afterInit();
            }
        }
        $this->engine->addToAdditions(['appendix' => '?v=' . self::$VERSION]);
        $this->engine->initSyntax();

        self::setInstance($this);

        // NavigationManager
        new NavigationManager();
    }

    public function match() {
        $this->router = new Router($this->collection);
        $this->route  = $this->router->matchCurrentRequest();
        if (!$this->route) {
            self::compile('_views/_blank.html', []);
            $this->finish();
            return;
        }
        $this->curroute = $this->route->getUrl();

        $this->finish();
    }

    /**
     * @return Engine
     */
    public static function getEngine() {
        return self::$instance->engine;
    }

    /**
     * @return Collection
     */
    public static function getCollection() {
        return self::$instance->collection;
    }

    /**
     * @return DataBase
     */
    public static function getDatabase() {
        return self::$instance->database;
    }

    /**
     * @return NavigationManager
     */
    public static function getNavManager() {
        return self::$instance->navmanager;
    }

    /**
     * @param $language
     */
    public static function setLanguage($language) {
        self::$instance->languageManager = $language;
    }

    /**
     * @return LanguageLoader
     */
    public static function getLanguage() {
        return self::$instance->languageManager;
    }

    /**
     * @param $newdatabase
     */
    public static function setDatabase($newdatabase) {
        self::$instance->database = $newdatabase;
    }

    /**
     * @param $newmanager
     */
    public static function setNavManager($newmanager) {
        self::$instance->navmanager = $newmanager;
    }

    /**
     * @param $newpermission
     */
    public static function setPermissionManager($newpermission) {
        self::$instance->permissionmanager = $newpermission;
    }

    /**
     * @param $instance Panel
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }

    /**
     * @return Panel
     */
    public static function getInstance() {
        return self::$instance;
    }

    /**
     * Returns the current Proxmox instance
     *
     * @return \ProxmoxVE\Proxmox
     */
    public static function getProxmox() {
        $client = new Client([
            'timeout' => 1000
        ]);
        $conf   = match (Settings::getConfigEntry("P_AUTH_MODE")) {
            'api' => [
                'hostname'     => Settings::getConfigEntry('P_HOST'),
                'token-id'     => Settings::getConfigEntry('P_TOKEN_ID', ""),
                'token-secret' => Settings::getConfigEntry('P_TOKEN_SECRET', "")
            ],
            default => [
                'hostname' => Settings::getConfigEntry("P_HOST"),
                'username' => Settings::getConfigEntry("P_USER"),
                'password' => Settings::getConfigEntry("P_PASSWORD")
            ]
        };

        if (!self::$proxmox)
            self::$proxmox = new Proxmox($conf, 'array', $client);

        return self::$proxmox;
    }

    public static function setMailHelper($i) {
        self::$mailhelper = $i;
    }

    /**
     * return current mailhelper instance
     *
     * @return \Controllers\MailHelper
     */
    public static function getMailHelper() {
        if (!isset(self::$mailhelper)) {
            new MailHelper(Settings::getConfigEntry("MAIL_HOST"), Settings::getConfigEntry("MAIL_USER"), Settings::getConfigEntry("MAIL_PASSWORD"));
        }
        return self::$mailhelper;
    }

    public static function getModules() {
        return self::$instance->modules;
    }

    /**
     * returns a module instance based on the installed modules
     *
     * @param string $module    the module to give
     * @return \Module\Module|bool           an instance of the Module
     */
    public static function getModule(string $searchModule) {
        foreach (self::getModules() as $module) {
            $name = explode('\\', $module->getName());
            if (end($name) === $searchModule) {
                return $module;
            }
        }
        return false;
    }

    public static function render() {
        self::$instance->content = self::$instance->engine->compile(self::$instance->template_file, self::$instance->params);
    }

    public static function compile(string $file, array $params = []) {
        self::getInstance()->template_file = $file;
        $style                             = ($_COOKIE['theme'] ?? false) == "dark" ? "dark" : "style";
        $force                             = Settings::getConfigEntry("FORCE_THEME", "dont");
        if ($force !== "dont") {
            $style = $force == "dark" ? "dark" : "style";
        }
        self::addToParams(array_merge([
            'STYLE'      => $style,
            "PAGE_TITLE" => Settings::getConfigEntry("PAGE_TITLE", "Proxmox Admin Panel"),
            "__USER"     => BaseModule::getUser()
        ], $params));
    }

    public static function addToParams(array $params) {
        self::getInstance()->params = array_merge(self::getInstance()->params, $params);
    }

    public static function addToInterceptors($data) {
        self::getInstance()->interceptors = array_merge($data, self::getInstance()->interceptors);
    }

    public static function executeIfModuleIsInstalled(string $searchModule, string $function, array $params = []) {
        $mod = self::getModule($searchModule);
        if (!$mod)
            return;
        return call_user_func_array($function, $params);
    }

    public function finish() {

        // execute VueModuleManager to find frontend-Modules we can load.
        // wether or not they are used by the frontend is not up here.
        $modules = VueModuleManager::match(self::getInstance()->route);
        self::addToParams(['vueModules' => json_encode($modules)]);
        self::render();

        foreach (self::getInstance()->interceptors as $interceptor) {
            if (self::getInstance()->curroute == $interceptor[0]) {
                $this->content = call_user_func($interceptor[1], $this->content);
            }
        }
        $add = [];
        if ($this->content === '') {
            header('Content-Type: application/json');
            if (isset($this->route->getOutput()['code'])) {
                $code = $this->route->getOutput()['code'];
                preg_match('/^\d{3}$/', $code, $matches, PREG_OFFSET_CAPTURE);
                if (sizeof($matches) > 0) {
                    $code = $matches[0][0];
                    http_response_code($code);
                    if (!isset($this->route->getOutput()['message'])) {
                        switch ($code) {
                            case 401:
                                $add['message'] = Panel::getLanguage()->get('global', 'm_permission_denied');
                                break;
                            case 403:
                                $add['message'] = "Forbidden";
                                break;
                            case 404:
                                $add['message'] = "Not Found";
                                break;
                            case 500:
                                $add['message'] = Panel::getLanguage()->get('global', 'm_internal_error');
                                break;
                        }
                    }
                }
            }
            $this->content = json_encode(array_merge($this->route->getOutput(), $add));

            // add cors headers

        }
        if (Settings::getConfigEntry('CORS', false)) {
            header('Access-Control-Allow-Origin: ' . Settings::getConfigEntry('CORS_ALLOW_ORIGIN', '*'));
            header('Access-Control-Allow-Methods: ' . Settings::getConfigEntry('CORS_ALLOW_METHODS', 'GET, POST'));
            header("Access-Control-Allow-Headers: " . Settings::getConfigEntry('CORS_ALLOW_HEADERS', '*'));
        }
        echo $this->content;
    }

    /**
     * get the input from a request that uses JSON (which should be the norm soon)
     *
     * @return array
     */
    public static function getRequestInput(): array {
        $headers = array_change_key_case(getallheaders());
        $ct      = $headers['content-type'] ?? '';
        if (strpos('application/json', $ct) !== false) {
            return json_decode(file_get_contents('php://input'), true) ?? [];
        }
        $_REQ = [];
        parse_str(file_get_contents('php://input'), $_REQ);
        return $_REQ ?? [];
    }
}