<?php
namespace Objects\Event\FlowComponents;

use Objects\Event\TemplateReplacer;

class Filter {

    /**
     * filter stuff
     * data:
     * - field
     * - operation (>, <, !=, =)
     * - value
     * 
     * @param array $data
     * @param array $parameters
     * @return array
     */
    public static function execute($data, $parameters) {
        $field     = $data['field'];
        $operation = $data['operation'];
        $value     = $data['value'];

        // extract the second last part
        $path         = explode('.', $field);
        $indexToCheck = sizeof($path) == 1 ? $path[0] : implode('.', array_slice($path, 0, -1));
        
        $isArray = TemplateReplacer::checkIfPartIsArray($field, $parameters, sizeof($path) - 2);
        if ($isArray) {
            // filter out where condition is met
            $field = TemplateReplacer::resolveNestedPathInData($indexToCheck, $parameters);
            $res   = current(array_filter($field, function ($e) use ($operation, $value, $path) {
                return self::resolveCondition($operation, $value, $e[end($path)]);
            }));

            // Split the path into an array
            $path_parts = explode('.', $indexToCheck);
            // Create a reference to traverse the array
            $copy = $parameters;
            $current = &$copy;
            // Traverse the array using the path
            foreach ($path_parts as $key) {
                if (!isset($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
            $current = $res;

            return $res ?
                ['__index' => 1, [...$copy]] :
                ['__index' => 2, $parameters];
        } else {
            $field = TemplateReplacer::resolveNestedPathInData($field, $parameters);
            $res   = self::resolveCondition($operation, $value, $field);
            return $res ? ['__index' => 1, $parameters] : ['__index' => 2, $parameters];
        }
    }

    private static function resolveCondition(string $operation, string $value, $field) {
        switch ($operation) {
            case '>':
                return $field > $value;
            case "<":
                return $field < $value;
            case "=":
                return $field == $value;
            case "!=":
                return $field != $value;
        }
    }
}