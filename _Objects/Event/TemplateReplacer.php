<?php
namespace Objects\Event;

class TemplateReplacer {

    /**
     * takes a string and replaces everything in curyl-brackets with the corresponding path in the $data
     *
     * @param string $input
     * @param [type] $data
     * @return string   
     */
    public static function replaceAll(string $input, $data) {
        preg_match_all("{{(?:(?:\s+)?)((\.?\w)+)(?:(\s+)?)}}", $input, $matches);
        foreach ($matches[1] as $match) {
            // resolve path
            $a     = self::resolveNestedPathInData($match, $data);
            $input = str_replace("{{{$match}}}", $a ?? '', $input);
        }
        return $input;
    }

    /**
     * resolve array of path instructions in nested data array
     *
     * @param [type] $path
     * @param [type] $data
     * @return mixed
     */
    public static function resolveNestedPathInData($path, $data) {
        $path = explode('.', $path);
        while (sizeof($path) > 0) {
            $ele = array_shift($path);
            if (is_array($data)) {
                $data = isset($data[$ele]) ? $data[$ele] : null;
            } else {
                $data = isset($data->$ele) ? $data->$ele : null;
            }
        }
        return $data;
    }

    /**
     * checks if the provided index in the path is an array.
     * 
     * e.g:
     * charges.id
     * 
     * checkIfPartIsArray('charges.id', ['charges' => [['id' => 1], ['id' => 2]]], 0);
     * --> would return true
     * 
     * 
     * checkIfPartIsArray('charges.id', ['charges' => ['id' => 1]], 0);
     * --> would return false
     *
     * @param string $path
     * @param [type] $data
     * @param integer $indexToCheck
     * @return boolean
     */
    public static function checkIfPartIsArray(string $path, $data, $indexToCheck = 0) {
        $path = explode('.', $path);
        $i    = 0;
        while (sizeof($path) > 0) {
            $ele = array_shift($path);
            $data = $data[$ele];
            if ($i == $indexToCheck) {
                return isset($data[0]) && is_array($data[0]);
            }
            $i++;
        }
        return false;

    }
}