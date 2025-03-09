<?php
namespace Objects\Event\FlowComponents;

use Objects\Event\TemplateReplacer;
use Objects\Ticket;

class CreateTicket {


    /**
     * creates a new ticket
     *
     * data:
     * - title
     * - priority
     * - creatorid
     * - content
     * 
     * @param array $data
     * @param array $parameters
     */
    public static function execute($data, $parameters) {
        $title    = TemplateReplacer::replaceAll($data['title'], $parameters);
        $priority = $data['priority'];
        $content  = TemplateReplacer::replaceAll($data['content'], $parameters);

        $creatorId = $parameters[$data['creatorid']];

        Ticket::createNewTicket($title, $creatorId, $content, $priority, 0);

    }
}