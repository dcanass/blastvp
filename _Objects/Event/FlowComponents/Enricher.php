<?php
namespace Objects\Event\FlowComponents;

use Controllers\Panel;
use Module\BaseModule\Controllers\IPAM\IPAM;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Objects\Event\TemplateReplacer;

class Enricher {


    /**
     * enricher, data:
     * - table
     * - inputfield
     * - joinfield
     * - asfield
     *
     * @param [type] $data
     * @return array
     */
    public static function execute($data, $parameters): array {
        $table      = $data['table'];
        $inputfield = TemplateReplacer::resolveNestedPathInData($data['inputfield'], $parameters);
        $joinfield  = TemplateReplacer::replaceAll($data['joinfield'], $parameters);
        $asfield    = $data['asfield'];
        if ($table == 'ipam_4') {
            $result = Panel::getDatabase()->custom_query(IPAM::fetchRange(4, "WHERE ipam_4.id = $inputfield"))->fetchAll(\PDO::FETCH_ASSOC)[0];
        } else if ($table == "ipam_6") {
            $result = Panel::getDatabase()->custom_query(IPAM::fetchRange(6, "WHERE ipam_6.id = $inputfield"))->fetchAll(\PDO::FETCH_ASSOC)[0];
        } else {
            $result = Panel::getDatabase()->custom_query(
                "SELECT * FROM `$table` WHERE `$joinfield`=?",
                [
                    $inputfield
                ])->fetchAll(\PDO::FETCH_ASSOC)[0];
            if ($table === 'packages') {
                $result['decoded_meta'] = json_decode($result['meta'], true);
            }
        }

        return [
            ...$parameters,
            $asfield => $result
        ];
    }

}