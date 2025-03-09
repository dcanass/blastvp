<?php

namespace Objects\Event;

use Exception;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\EventLog;
use Tracy\Debugger;

/**
 * EventManager class. Events are like hooks which can get executed at a certain time during the execution 
 * of a function. The idea is, to execute special code on e.g. server reset or deletion to intercept
 * function calls at a specific point in time and not to break the flow of the application.
 * 
 * Events get a context, which needs to be documented, like the server object or an ID
 * 
 * Event-Handlers get executed in a non-predictable order so their outcome cannot depend on any other hook
 * 
 */
class EventManager {

    public static $events = [];
    public static $enrichers = [];
    public static $actions = [];

    public static function fire($event, ...$parameters) {
        // validate $parameters with $event->getParameters();
        $event = self::$events[$event];

        if (sizeof($parameters) != sizeof($event->getParameters())) {
            throw new Exception("Invalid parameter count for event {$event->getName()} Found: " . sizeof($parameters) . "/" . sizeof($event->getParameters()) . ". Expected the following parameters: " . join(", ", array_keys($event->getParameters())));
        }

        $listeners = $event->getListeners();
        foreach ($listeners as $listener) {
            call_user_func($listener, ...$parameters);
        }

        self::runAutomated($event->getName(), $parameters[0]);
    }

    /**
     * adds a new event to the EventManager. Only registered events are able to be executed
     *
     * @param [type] $event
     * @return void
     */
    public static function registerEvent(Event $event) {
        if (!isset(self::$events[$event->getName()])) {
            self::$events[$event->getName()] = $event;
        } else {
            // event already exists, merge parameters
            self::$events[$event->getName()]->setDescription($event->getDescription())->setParameters($event->getParameters())->setFriendlyName($event->getFriendlyName());
        }
    }

    /**
     * register a new handler function for an event
     *
     * @param [type] $event
     * @param [type] $handler
     * @return void
     */
    public static function registerHandler(string $event, string $handler) {
        if (!isset(self::$events[$event])) {
            // event is not registered. This might happen due to the dynamic nature of the module-loading.
            // if we encounter this, we register a placeholder event. When the original #registerEvent then gets fired, 
            // we merge the new entry with the existing entry
            self::registerEvent((new Event())->setName($event));
        }
        self::$events[$event]->addListener($handler);
    }

    public static function registerEnrichers(Enricher ...$enrichers) {
        foreach ($enrichers as $enricher) {
            self::registerEnricher($enricher);
        }
    }
    public static function registerEnricher(Enricher $enricher) {
        self::$enrichers[] = $enricher;
    }

    /**
     * return all registered events and handlers
     *
     * @return array
     */
    public static function listEvents() {
        return self::$events;
    }

    public static function listEnrichers() {
        return self::$enrichers;
    }

    public static function listActions() {
        return self::$actions;
    }

    public static function registerActions(Action ...$actions) {
        foreach ($actions as $action) {
            self::registerAction($action);
        }
    }

    public static function registerAction(Action $action) {
        self::$actions[] = $action;
    }

    public static function runAutomated(string $event, $params) {
        $eventEngine = new EventEngine($event);
        $user        = BaseModule::getUser();
        if ($user) {
            $ogPermission = $user->getPermission();
            $user->setPermission(3);
        }
        try {
            $eventEngine->dispatch($params);
        } catch (Exception $e) {
            EventLog::log("Error during the execution of a event flow: " . $e->getMessage(), EventLog::WARNING);
            // ignore any errors
            dump($e->getMessage());
        } finally {
            //
            if ($user)
                $user->setPermission($ogPermission);
        }
    }
}
