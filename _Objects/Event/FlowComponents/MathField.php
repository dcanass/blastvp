<?php
namespace Objects\Event\FlowComponents;

use Objects\Event\TemplateReplacer;

class MathField {

    /**
     * math field allows mathematical operations
     *
     * data:
     * - field1
     * - field2
     * - operation (+ - / * %)
     * - asfield
     */
    public static function execute($data, $parameters) {
        $field1    = TemplateReplacer::replaceAll($data['field1'], $parameters);
        $field2    = TemplateReplacer::replaceAll($data['field2'], $parameters);
        $asField   = TemplateReplacer::replaceAll($data['asfield'], $parameters);
        $operation = $data['operation'];

        $result = "";
        switch ($operation) {
            case '+':
                $result = $field1 + $field2;
                break;
            case '-':
                $result = $field1 - $field2;
                break;
            case '/':
                $result = $field1 / $field2;
                break;
            case '*':
                $result = $field1 * $field2;
                break;
            case '%':
                $result = $field1 % $field2;
                break;
        }



        return [
            ...$parameters,
            $asField => $result
        ];
    }
}