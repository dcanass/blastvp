<?php
namespace Objects\Event\FlowComponents;

use Controllers\Panel;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Objects\Event\TemplateReplacer;

class IPAM {


    /**
     * enricher, data:
     * - asfield
     * - type => ipv4 || ipv6
     * - node
     * - userid
     *
     * @param [type] $data
     * @return array
     */
    public static function execute($data, $parameters): array {
        $type    = TemplateReplacer::replaceAll($data['type'], $parameters);
        $asfield = TemplateReplacer::replaceAll($data['asfield'], $parameters);
        $node    = TemplateReplacer::replaceAll($data['node'], $parameters);
        $userId  = TemplateReplacer::replaceAll($data['userid'], $parameters);

        $result = IPAMHelper::getFreeIp($type == "ipv4" ? '4' : '6', $node, $userId);

        return [
            ...$parameters,
            $asfield => (array) $result['ip']
        ];
    }

}