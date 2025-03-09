<?php
namespace Objects\Event\FlowComponents;

use Module\BaseModule\Controllers\Tags;
use Objects\Event\TemplateReplacer;

class LoadTags {

    /**
     * load tags to a resource
     * data:
     * - asfield => as which field
     * - joinfield => the primary key
     * - resource => the resource-type to join
     *
     * @param array $data
     * @param array $parameters
     * @return array
     */
    public static function execute($data, $parameters) {
        $asfield   = TemplateReplacer::replaceAll($data['asfield'], $parameters);
        $joinfield = $parameters[$data['joinfield']];
        $resource  = $data['resource'];

        $tags = Tags::internalGet("$resource-$joinfield");

        return [
            ...$parameters,
            $asfield => $tags
        ];
    }
}