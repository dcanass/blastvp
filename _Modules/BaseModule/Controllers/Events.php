<?php
namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Event\Action;
use Objects\Event\Enricher;
use Objects\Event\EventManager;
use Objects\Event\FlowComponents\SendMail;
use Objects\Event\FlowComponents\Webhook;

class Events {

    public static function render() {
        Panel::compile('_views/_pages/admin/events.html', [
            'm_title' => 'Events',
            ...Panel::getLanguage()->getPages(['global'])
        ]);
    }

    public static function apiGet() {
        return Panel::getDatabase()->fetch_all('events');
    }

    public static function apiGetSingle($id) {
        return Panel::getDatabase()->fetch_single_row('events', 'id', $id, \PDO::FETCH_ASSOC);
    }

    public static function apiCreate() {
        $b = Panel::getRequestInput();

        Panel::getDatabase()->insert('events', [
            'friendlyName' => $b['friendlyName'],
            'description'  => $b['description']
        ]);

        return ['error' => false, 'id' => Panel::getDatabase()->get_last_id()];
    }

    public static function apiPatch($id) {
        $b = Panel::getRequestInput();

        Panel::getDatabase()->update('events', [
            'description' => $b['description'],
            'enabled'     => $b['enabled']
        ], 'id', $id);

        return ['error' => false];
    }

    public static function apiGetEvents() {
        return array_values(array_map(function ($e) {
            return [
                'v' => $e->getName(),
                'k' => $e->getFriendlyName()
            ];
        }, EventManager::listEvents()));
    }

    public static function apiDelete($id) {
        return ['success' => Panel::getDatabase()->delete('events', 'id', $id)];
    }

    /**
     * get a list of all enrichers
     *
     * @return array
     */
    public static function apiGetEnrichers() {
        return array_map(function (Enricher $e) {
            return [
                'name'   => $e->getName(),
                'table'  => $e->getTable(),
                'fields' => $e->getFields()
            ];
        }, EventManager::listEnrichers());
    }

    public static function apiGetActions() {
        return array_map(function (Action $e) {
            return [
                'name'          => $e->getName(),
                'url'           => $e->getUrl(),
                'componentName' => $e->getComponentName(),
                'display'       => $e->getDisplay()
            ];
        }, EventManager::listActions());
    }

    public static function apiPostTestAction() {
        $b    = Panel::getRequestInput();
        $user = BaseModule::getUser();

        try {
            $action = match ($b['action']) {
                'send-email' => SendMail::execute($b['data'], $b['parameters']),
                'send-webhook' => Webhook::execute($b['data'], $b['parameters'])
            };

            return [
                'status' => 'ok',
                'result' => $action
            ];
        } catch (\Exception $e) {
            //
            return [
                'status' => 'nok',
                'result' => $e->getMessage()
            ];
        }
    }
}