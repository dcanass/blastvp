<?php
namespace Objects\Event\FlowComponents;

use Objects\Event\TemplateReplacer;


class AddField {

    /**
     * add-field enables you to add a field to the flow
     * 
     * data:
     * - field
     * - value
     */
    public static function execute($data, $parameters) {
        $field = TemplateReplacer::replaceAll($data['field'], $parameters);
        $value = TemplateReplacer::replaceAll($data['value'], $parameters);
        return [
            ...$parameters,
            $field => $value
        ];
    }
}