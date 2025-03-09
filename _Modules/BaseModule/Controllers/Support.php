<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 25.02.19
 * Time: 19:50
 */

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\LXCModule\Objects\ContainerUser;
use Objects\Event\TemplateReplacer;
use Objects\Notification;
use Objects\Ticket;
use Objects\User;

class Support {

    public static function listAll() {
        $user = BaseModule::getUser();

        return array_map(function ($e) {
            return new Ticket($e);
        },
            Panel::getDatabase()->custom_query("SELECT * FROM tickets WHERE userid=? AND status=0 ORDER BY updatedAt DESC",
                [
                    'userid' => $user->getId()
                ]
            )->fetchAll()
        );
    }
    public static function overview() {

        $user    = BaseModule::getUser();
        $tickets = Ticket::loadAllTickets($user->getId());

        if (isset($_GET['view']) && $_GET['view'] == "closed") {
            $tickets = Ticket::filterClosedTickets($tickets);
        } else {
            $tickets = Ticket::filterOpenTickets($tickets);
        }

        $products = $user->getServers();

        $containers = [];
        if (Panel::getModule('LXCModule')) {
            $containers = (new ContainerUser($user))->getContainers();
        }

        Panel::compile("_views/_pages/support/overview.html", array_merge([
            "tickets"    => (array) $tickets,
            "servers"    => (array) $products,
            "containers" => $containers
        ], Panel::getLanguage()->getPages(['support', 'global'])));
    }

    public static function admin_overview() {
        $user = BaseModule::getUser();

        if ($user->getPermission() < 2) {
            die('401');
        }

        $query = <<<SQL
            SELECT
                tickets.*, tickets_messages.message
            FROM tickets
            LEFT JOIN tickets_messages ON tickets.id = tickets_messages.ticketid
            GROUP BY tickets.id DESC
        SQL;

        $res = Panel::getDatabase()->custom_query($query)->fetchAll();

        $tickets = array_map(function ($element) {
            return new Ticket($element);
        }, $res);

        if (isset($_GET['view']) && $_GET['view'] == "closed") {
            $tickets = Ticket::filterClosedTickets($tickets);
        } else {
            $tickets = Ticket::filterOpenTickets($tickets);
        }

        if (isset($_GET['action_filter'])) {
            if ($_GET['action_filter'] == "awaiting_support") {
                $tickets = Ticket::filterReponseStatus($tickets, "AWAITING_SUPPORT");
            } else if ($_GET['action_filter'] == "awaiting_customer") {
                $tickets = Ticket::filterReponseStatus($tickets, "AWAITING_CUSTOMER");
            }
        }

        Panel::compile("_views/_pages/admin/tickets.html", array_merge([
            "tickets" => (array) $tickets
        ], Panel::getLanguage()->getPages(['support', 'admin_support', 'global'])));
    }

    public static function ticket($id) {
        $user     = BaseModule::getUser();
        $ticket   = new Ticket(Panel::getDatabase()->fetch_single_row('tickets', 'id', $id));
        $messages = Panel::getDatabase()->custom_query('SELECT * FROM tickets_messages WHERE tickets_messages.ticketid=?', ['ticketid' => $id])->fetchAll(\PDO::FETCH_ASSOC);
        if ($ticket->userid != $user->getId() && $user->getPermission() < 2) {
            die('401');
        }

        $messages = array_map(function ($ele) use ($ticket, $user) {
            $ele['pos']    =
                ($ticket->userid == $user->getId()) ? (
                    ($ele['userid'] == $user->getId()) ? "replies" : "sent") : ($ele['pos'] = ($ele['userid'] == $ticket->userid) ? "sent" : "replies");
            $ele['author'] = (new User($ele['userid']))->load();
            return $ele;
        }, $messages);

        Panel::compile("_views/_pages/support/ticket.html", array_merge([
            'ticket'   => $ticket,
            'messages' => $messages
        ], Panel::getLanguage()->getPages(['ticket', 'support', 'global'])));
    }

    public static function createTicket() {
        $title    = $_POST['title'] ?? null;
        $message  = $_POST['message'] ?? null;
        $priority = $_POST['priority'] ?? null;
        $product  = $_POST['product'] ?? null;

        if (
            $title == null ||
            $message == null ||
            $priority == null ||
            $product == null
        ) {
            return [
                'error' => true,
                'title' => Panel::getLanguage()->get('support', 'm_api_error'),
                'text'  => Panel::getLanguage()->get('support', 'm_api_empty'),
                $title, $message, $priority, $product
            ];
        }

        $id = Ticket::createNewTicket($title, BaseModule::getUser()->getId(), $message, $priority, $product);

        return [
            'ticketid' => $id
        ];
    }

    public static function answerTicket() {
        $message  = $_POST['message'];
        $ticketid = $_POST['ticketid'];

        header('Content-Type: application/json');

        $ticket = Panel::getDatabase()->fetch_single_row('tickets', 'id', $ticketid, \PDO::FETCH_OBJ);
        if (!$ticket) {
            die(json_encode([
                'error' => true,
                'title' => '404',
                'text'  => 'Ticket not found'
            ]));
        }
        $user = BaseModule::getUser();
        if ($ticket->userid != $user->getId() && $user->getPermission() < 2) {
            die(json_encode([
                'error' => true,
                'title' => '401',
                'text'  => "You are not authorized to write in this ticket"
            ]));
        }

        if (trim($message) == "") {
            die(json_encode([
                'error' => true,
                'title' => '400',
                'text'  => "Forbidden message content"
            ]));
        }

        $author = (new User($ticket->userid))->load();
        if ($user->getPermission() > 1)
            $message = TemplateReplacer::replaceAll($message, [
                'ticket' => [
                    'id' => $ticketid
                ],
                'user'   => [
                    'name'  => $author->getName(),
                    'email' => $author->getEmail()
                ]
            ]);

        Ticket::insertAnswer($ticketid, $message, $user->getId());
        if ($ticket->userid != $user->getId()) {
            // the author of the answer is not the one who created the ticket. Set him as the corresponding supp
            Ticket::setSupporter($ticket->id, $user->getId());
        }

        $adminsAndSupporters = Panel::getDatabase()->custom_query("SELECT * FROM users WHERE permission > 1")->fetchAll(\PDO::FETCH_OBJ);
        $users               = [];

        foreach ($adminsAndSupporters as $adminOrSupporter) {
            $users[] = (new User($adminOrSupporter->id))->load();
        }
        $users[] = $author;

        foreach ($users as $_user) {
            if ($_user->hasNotificationsEnabled('tickets')) {
                // user has ticket notifications enabled, so we need to send him a message her.
                $notification = (new Notification())
                    ->setUserId($_user->getId())
                    ->setType(Notification::TYPE_TICKETS)
                    ->setEmail($_user->getEmail())
                    ->setMeta("ticket_answer_" . $ticketid);
                $notification->save();
            }
        }
        die(json_encode(['error' => false]));
    }

    public static function closeTicket($id) {
        header('Content-Type: application/json');

        $ticket = Panel::getDatabase()->fetch_single_row('tickets', 'id', $id, \PDO::FETCH_OBJ);
        if (!$ticket) {
            die(json_encode([
                'error' => true,
                'title' => '404',
                'text'  => 'Ticket not found'
            ]));
        }
        $user = BaseModule::getUser();
        if ($ticket->userid != $user->getId() && $user->getPermission() < 2) {
            die(json_encode([
                'error' => true,
                'title' => '401',
                'text'  => "You are not authorized"
            ]));
        }
        Ticket::close($id);
        $adminsAndSupporters = Panel::getDatabase()->custom_query("SELECT * FROM users WHERE permission > 1")->fetchAll(\PDO::FETCH_OBJ);
        $users               = [];

        foreach ($adminsAndSupporters as $adminOrSupporter) {
            $users[] = (new User($adminOrSupporter->id))->load();
        }
        $users[] = (new User($ticket->userid))->load();

        foreach ($users as $_user) {
            if ($_user->hasNotificationsEnabled('tickets')) {
                $notification = (new Notification())
                    ->setUserId($_user->getId())
                    ->setType(Notification::TYPE_TICKETS)
                    ->setEmail($_user->getEmail())
                    ->setMeta("ticket_closed_" . $id);
                $notification->save();
            }
        }
        die(json_encode([
            'error' => false
        ]));
    }

    public static function reopenTicket($id) {
        header('Content-Type: application/json');

        $ticket = Panel::getDatabase()->fetch_single_row('tickets', 'id', $id, \PDO::FETCH_OBJ);
        if (!$ticket) {
            die(json_encode([
                'error' => true,
                'title' => '404',
                'text'  => 'Ticket not found'
            ]));
        }
        $user = BaseModule::getUser();
        if ($ticket->userid != $user->getId() && $user->getPermission() < 2) {
            die(json_encode([
                'error' => true,
                'title' => '401',
                'text'  => "You are not authorized"
            ]));
        }
        Ticket::reopen($id);
        $adminsAndSupporters = Panel::getDatabase()->custom_query("SELECT * FROM users WHERE permission > 1")->fetchAll(\PDO::FETCH_OBJ);
        $users               = [];

        foreach ($adminsAndSupporters as $adminOrSupporter) {
            $users[] = (new User($adminOrSupporter->id))->load();
        }
        $users[] = (new User($ticket->userid))->load();

        foreach ($users as $_user) {
            if ($_user->hasNotificationsEnabled('tickets')) {
                // user has ticket notifications enabled, so we need to send him a message her.
                $notification = (new Notification())
                    ->setUserId($_user->getId())
                    ->setType(Notification::TYPE_TICKETS)
                    ->setEmail($_user->getEmail())
                    ->setMeta("ticket_reopened_" . $id);
                $notification->save();
            }
        }
        die(json_encode([
            'error' => false
        ]));
    }

    public static function apiSingleTicket($id) {
        $user     = BaseModule::getUser();
        $ticket   = new Ticket(Panel::getDatabase()->fetch_single_row('tickets', 'id', $id));
        $messages = Panel::getDatabase()->custom_query('SELECT * FROM tickets_messages WHERE tickets_messages.ticketid=?', ['ticketid' => $id])->fetchAll(\PDO::FETCH_ASSOC);
        if ($ticket->userid != $user->getId() && $user->getPermission() < 2) {
            die('401');
        }

        $messages = array_map(function ($ele) use ($ticket, $user) {
            $ele['pos']    =
                ($ticket->userid == $user->getId()) ? (
                    ($ele['userid'] == $user->getId()) ? "replies" : "sent") : ($ele['pos'] = ($ele['userid'] == $ticket->userid) ? "sent" : "replies");
            $author        = (new User($ele['userid']))->load();
            $ele['author'] = [
                'name'           => $author->getName(),
                'profilePicture' => $author->getProfilePicture()
            ];
            return $ele;
        }, $messages);

        return $messages;
    }

    /**
     * get a list of all available support templates
     * 
     * @return array
     */
    public static function getTemplates() {
        $user = BaseModule::getUser();

        if ($user->getPermission() < 2) {
            return [
                'code' => 403
            ];
        }
        return Panel::getDatabase()->fetch_all('support_templates');
    }

    /**
     * create a new template
     * 
     * @return array
     */
    public static function createTemplate() {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        if ($user->getPermission() < 2) {
            return [
                'code' => 403
            ];
        }
        $id = Panel::getDatabase()->insert('support_templates', [
            'friendlyName' => $b['friendlyName'],
            'command'      => $b['command'],
            'body'         => $b['body']
        ]);

        return [
            'error' => $id !== 1
        ];
    }

    /**
     * update a single template
     * 
     * @param mixed $id
     * @return array
     */
    public static function updateTemplate($id) {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        if ($user->getPermission() < 2) {
            return [
                'code' => 403
            ];
        }

        $id = Panel::getDatabase()->update('support_templates', [
            'friendlyName' => $b['friendlyName'],
            'command'      => $b['command'],
            'body'         => $b['body']
        ], 'id', $b['id']);

        return [
            'error' => $id !== 1
        ];
    }

    public static function createForUser() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            return [
                'code' => 403
            ];
        }

        $b = Panel::getRequestInput();

        $id = Ticket::createNewTicket(
            $b['title'], 
            $b['user'], 
            "", 
            $b['priority'], 
            0
        );

        Ticket::insertAnswer($id, $b['content'], $user->getId());

        return [
            'ticketid' => $id
        ];

    }
}