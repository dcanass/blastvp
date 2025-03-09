<?php

namespace Controllers;


class ConfigValidator {

    private $options = array();

    public function __construct() {

        $config = file_get_contents(__DIR__ . "/../config.json");
        if (!$config) die("Failed to open config.json file. Does it exists and has right access?");

        $config = json_decode($config);
        if (json_last_error()) die("config: " . json_last_error_msg() . "! use a tool like <a href='https://jsonlint.com/'>this</a> to confirm that your config.json is correct!");
        foreach ($config as $key => $value) {
            if (!defined($key)) {
                define($key, is_object($value) ? serialize($value) : $value);
            }
        }

        foreach (glob("_Modules/**/module.json") as $file) {
            $this->addToOptions(json_decode(file_get_contents($file), true));
        }
        $this->checkOptions();
    }

    private function addToOptions($ar) {
        $this->options = array_merge($this->options, $ar);
    }

    private function checkOptions() {
        foreach ($this->options as $validPoint => $desc) {
            if (!defined($validPoint)) {
                echo "Missing config entry: " . $validPoint . " (" . $desc['exp'] . ")<br>";
                echo "Example:<br><br><code>\"" . $validPoint . "\": " . $desc['exa'] . ",</code><br><br>";
                echo "Please make the changes in the config.json and test the config file with <a href='https://jsonlint.com/'>this</a> tool";
                die();
            }
        }
    }
}
