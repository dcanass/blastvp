<?php
namespace Objects\Event;

use Controllers\Panel;
use Objects\Event\FlowComponents\Webhook;

class EventEngine {

    /**
     * initalize the event engine with a new event
     *
     * @param [type] $event
     */
    public function __construct(
        private readonly string $event
    ) {
    }

    /**
     * dispatch the event flow.
     *
     * @param [type] $parameters
     * @return void
     */
    public function dispatch($parameters) {
        $matchingEvents = $this->loadMatchingEvents();
        foreach ($matchingEvents as $event) {
            $this->executeFlow($event['description'], $parameters);
        }
    }

    /**
     * loads matching event, e.g. where the event trigger is the event we are currently looking at.
     *
     * @return array
     */
    private function loadMatchingEvents() {

        $events = Panel::getDatabase()->custom_query('SELECT * FROM events WHERE `enabled`=1')->fetchAll(\PDO::FETCH_ASSOC);

        $events = array_map(function ($e) {
            $e['description'] = json_decode($e['description'], true);
            return $e;
        }, $events);

        $take = [];
        foreach ($events as $event) {
            // filter for nodes that have the name = event and data->event == $this->event
            foreach ($event['description']['drawflow']['Home']['data'] as $node) {
                if ($node['name'] == 'event' && $node['data']['event'] == $this->event) {
                    $take[] = $event;
                }
            }
        }
        return $take;
    }

    private function executeFlow($inp, $parameters) {
        $nodelist = $inp['drawflow']['Home']['data']; // this is static 

        $registry = new NodeRegistry();
        foreach ($nodelist as $node) {
            $registry->addNode($node['id'], new Node($node));
        }

        $registry->connectAll();

        // start flow at the 'event' node.
        $start = null;
        foreach ($registry->getNodes() as $node) {
            if ($node->getName() === 'event' && $node->getData()['event'] === $this->event) {
                $start = $node;
            }
        }
        if (!$start) {
            dump('No starting point found.');
        }
        $start->execute($parameters);
    }
}