<?php
namespace Objects\Event\FlowComponents;

use Objects\Event\TemplateReplacer;

class LoadRest {

    /**
     * rest enricher, data:
     * - url
     * - method
     * - content
     * - contenttype
     * - headers JSON-encoded array of {key: "", value:""}
     *
     * @param [type] $data
     * @return array
     */
    public static function execute($data, $parameters) {
        $asfield = TemplateReplacer::replaceAll($data['asfield'], $parameters);

        $response = Webhook::execute($data, $parameters);
        $response = json_decode($response->getBody(), true);

        return [
            ...$parameters,
            $asfield => $response
        ];
    }

}