<?php

namespace Controllers;

class ModuleLoader {

  private $config_mods;

  public function loadAll(): array {

    $_mods             = [];
    $mods              = [];
    $this->config_mods = defined("MODULES") ? unserialize(MODULES) : new \stdClass();
    foreach (glob("_Modules/**/*.php") as $module_path) {
      $_mods[] = $module_path;
    }

    // sort that BaseModule is always the first entry
    usort($_mods, function ($a, $b) {
      if (strpos($a, 'BaseModule') !== false) {
        return -1;
      }
      if (strpos($b, 'BaseModule') !== false) {
        return 1;
      }
      return strcmp($a, $b);
    });

    foreach ($_mods as $module_path) {
      $mod         = explode('/', $module_path);
      $module_name = explode('.', $mod[2])[0];
      if ($module_name == "BaseModule") {
        $this->config_mods->$module_name = true;
      }
      $classname = "\\Module\\" . $mod[1] . "\\" . $module_name;

      if (isset($this->config_mods->$module_name) && $this->config_mods->$module_name) {
        $mods[] = new $classname();
      } else {
        $this->config_mods->$module_name = false;
      }
    }

    return $mods;
  }

  public static function saveToConfig($current_mods) {
    $config          = file_get_contents("config.json");
    $config          = json_decode($config);
    $config->MODULES = $current_mods;
    file_put_contents("config.json", json_encode($config, JSON_PRETTY_PRINT));
  }
}
