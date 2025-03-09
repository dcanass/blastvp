<?php

namespace Controllers;


use Exception;
use Module\BaseModule\Controllers\Admin\Settings;

class LanguageLoader {

    private $messages, $rawMessages;

    public function __construct() {
        $lang = $this->getCurrentLanguage(true);
        setlocale(LC_ALL, $lang . "_" . strtoupper($lang));

        $this->load('en');
        $this->load($lang);

        Panel::getInstance()->setLanguage($this);
    }

    private function load($lang) {
        $overwriteFile = __DIR__ . '/../_languages/' . $lang . '/overwrite.json';
        $files         = array_diff(glob(__DIR__ . '/../_languages/' . $lang . '/*.json'), [$overwriteFile]);
        if (empty($files)) {
            $languages = array_map(function ($dir) {
                return explode("/", $dir)[1];
            }, glob('_languages/*', GLOB_ONLYDIR));
            die("Invalid Language specified. Please use one of these: " . implode(', ', $languages));
        }
        foreach ($files as $languageFile) {
            try {
                $cont = json_decode(file_get_contents($languageFile), true);
                if (\json_last_error() !== JSON_ERROR_NONE) {
                    die("Language file error in " . $languageFile);
                }
                $this->add_to_languages($cont);
            } catch (Exception $e) {
                echo "Error in the language file(s)";
            }
        }

        // load and potentially merge overwrite file.
        try {
            $overwrite = @file_get_contents($overwriteFile);
            if ($overwrite) {
                $overwrite = json_decode($overwrite, true);
                if (\json_last_error() !== JSON_ERROR_NONE) {
                    die("Overwrite language file has wrong format in: " . $overwriteFile);
                }
                $this->add_to_languages($overwrite, true);
            }
        } catch (Exception $e) {
        }
    }

    private function add_to_languages($array1, $isOverwrite = false) {
        foreach ($array1 as $key => $permObj) {
            if (!isset($this->messages[$key])) {
                $this->messages[$key] = [];
            }
            foreach ($permObj as $key2 => $pem) {
                if (is_array($pem)) {
                    foreach ($pem as $k => $v) {
                        $this->messages[$key][$key2][$k] = ($v);
                    }
                } else {
                    $this->messages[$key][$key2] = ($pem);
                }
            }
            if (!$isOverwrite) {
                if (!isset($this->rawMessages[$key])) {
                    $this->rawMessages[$key] = [];
                }
                foreach ($permObj as $key2 => $pem) {
                    if (is_array($pem)) {
                        foreach ($pem as $k => $v) {
                            $this->rawMessages[$key][$key2][$k] = ($v);
                        }
                    } else {
                        $this->rawMessages[$key][$key2] = ($pem);
                    }
                }
            }
        }
    }

    public function getPage($page) {
        return $this->messages[$page] ?? [];
    }

    public function getPages($pages) {
        $res = [];
        foreach ($pages as $page) {
            $res = array_merge($res, $this->getPage($page));
        }
        return $res;
    }

    public function get($page, $key) {
        return html_entity_decode($this->messages[$page][$key]);
    }

    public function getOriginal($page, $key) {
        $v = $this->rawMessages[$page][$key];
        if (is_array($v))
            return $v;
        return html_entity_decode($v);
    }

    public function getRaw() {
        return $this->messages;
    }

    public function getListOfLanguages($doNotTranslate = false) {
        return array_values(array_map(function ($dir) use ($doNotTranslate) {
            $v = explode("/", $dir)[1];
            if ($v == "en" && !$doNotTranslate)
                return "gb";
            return $v;
        }, glob('_languages/*', GLOB_ONLYDIR)));
    }

    public function getCurrentLanguage($doNotTranslate = false) {
        // check enabled languages - if it's only one, take that
        $enabled = Settings::getConfigEntry("ENABLED_LANGUAGES", $this->getListOfLanguages(true));
        if (sizeof($enabled) <= 1) {
            if (sizeof($enabled) === 1)
                return $enabled[0] == 'en' ? 'gb' : $enabled[0];
            return Settings::getConfigEntry("LANGUAGE", 'gb');
        }
        // check if user has specific cookie for language, otherwise take LANGUAGE value
        $lang = Settings::getConfigEntry("LANGUAGE", "en");
        if (isset($_COOKIE['language']) && trim($_COOKIE['language']) != "") {
            $lang = $_COOKIE['language'];
        }

        if (!in_array($lang, $enabled)) {
            return Settings::getConfigEntry("LANGUAGE", 'gb');
        }

        if ($lang == "en" && !$doNotTranslate)
            return "gb";
        return $lang;
    }
}