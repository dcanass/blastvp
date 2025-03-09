<?php

namespace Objects;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Event\EventManager;

class Ticket {

    public $id;
    public $userid;
    public $title;
    public $priority;
    public $productId;
    public $product;
    public $userId;
    public $createdAt;
    public $updatedAt;
    public $assigned;
    public $status;
    public $_status;
    public $message;
    public $user;

    public $responseStatus, $_responseStatus;

    public function __construct($data) {
        $this->userid    = $data->userid;
        $this->id        = $data->id;
        $this->title     = $data->title;
        $this->priority  = $data->priority;
        $this->productId = $data->productId;
        $this->createdAt = Formatters::formatTicketDate($data->createdAt);
        $this->updatedAt = Formatters::formatTicketDate($data->updatedAt);

        if ($data->assigned == 0) {
            $this->assigned                 = new User(0, Panel::getLanguage()->get('support', 'm_no_supporter'), '');
            $this->assigned->profilePicture = $this->assigned->getProfilePicture();
        } else {
            $this->assigned = (new User($data->assigned))->load();

        }

        $this->status  = Formatters::getTicketStatus($data->status);
        $this->_status = $data->status;
        $this->message = $data->message ?? "";

        // if productId = 0 then it's a general question
        $this->product = $this->productId == 0 ?
            Panel::getLanguage()->get('support', 'm_modal_t_product_general') :
            Panel::getDatabase()->fetch_single_row('servers', 'id', $data->productId)->hostname ?? Panel::getLanguage()->get('support', 'm_modal_t_product_general');
        // load product from DB
        $this->user     = (new User($data->userid))->load();
        $this->priority = Formatters::formatPriority($this->priority);

        $this->_responseStatus = 'AWAITING_CUSTOMER';
        // load latest response to determine if there is customer action or support action awaited
        $lastMessage = Panel::getDatabase()->custom_query('SELECT * FROM tickets_messages WHERE ticketid=? ORDER BY createdAt DESC LIMIT 1', ['id' => $this->id])->fetchAll(\PDO::FETCH_ASSOC);
        if (sizeof($lastMessage) > 0) {
            $author                = $lastMessage[0]['userid'];
            $this->_responseStatus = $author == $this->user->getId() ? 'AWAITING_SUPPORT' : "AWAITING_CUSTOMER";
        }
        $this->responseStatus = Panel::getLanguage()->get('admin_support', $this->_responseStatus);
    }

    public static function loadAllTickets($userId) {
        $query = <<<SQL
            SELECT 
                tickets.*, tickets_messages.message 
            FROM 
                tickets 
            LEFT JOIN 
                tickets_messages
            ON tickets.id = (
                SELECT 
                    tickets_messages.ticketid FROM tickets_messages
                WHERE tickets_messages.ticketid = tickets.id ORDER BY tickets_messages.id DESC LIMIT 1)
            WHERE tickets.userid = ?
        SQL;

        $query = <<<SQL
            SELECT
                tickets.*, tickets_messages.message
            FROM tickets
            LEFT JOIN tickets_messages ON tickets.id = tickets_messages.ticketid
            WHERE tickets.userid = ?
            GROUP BY tickets.id DESC
        SQL;

        $res = Panel::getDatabase()->custom_query($query, ['userid' => $userId])->fetchAll();

        return array_map(function ($element) {
            return new self($element);
        }, $res);
    }

    public static function filterOpenTickets($tickets) {
        return array_filter($tickets, function ($ele) {
            return $ele->_status == 0;
        });
    }

    public static function filterClosedTickets($tickets) {
        return array_filter($tickets, function ($ele) {
            return $ele->_status == 1;
        });
    }

    public static function filterReponseStatus($tickets, $status) {
        return array_filter($tickets, function ($ele) use ($status) {
            return $ele->_responseStatus == $status;
        });
    }

    /**
     * creates a new Ticket
     *
     * @param string $title
     * @param int $author
     * @param string $message
     * @param int $priority
     * @param int $product
     * @return int
     */
    public static function createNewTicket($title, $author, $message, $priority, $product) {

        $title   = strip_tags($title);
        $message = strip_tags($message);

        Panel::getDatabase()->insert('tickets', [
            'title'     => $title,
            'status'    => 0,
            'priority'  => $priority,
            'productId' => $product,
            'userid'    => $author
        ]);
        $ticketId = Panel::getDatabase()->get_last_id();
        // insert first message in other table
        if ($message != "")
            self::insertAnswer($ticketId, $message, $author);

        $author              = (new User($author))->load();
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
                    ->setMeta("ticket_created_" . $ticketId);
                $notification->save();
            }
        }

        EventManager::fire('ticket::create', [
            'id'        => $ticketId,
            'userid'    => $author->getId(),
            'title'     => $title,
            'priority'  => $priority,
            'productid' => $product,
            'createdAt' => time(),
            'updatedAt' => time(),
            'status'    => 0
        ]);

        return $ticketId;
        // send email if actived @todo
    }

    public static function insertAnswer($ticketid, $message, $author) {
        $message = strip_tags($message);

        $res = Panel::getDatabase()->insert('tickets_messages', [
            'ticketid' => $ticketid,
            'message'  => $message,
            'userid'   => $author
        ]);
        Panel::getDatabase()->update('tickets', [
            'updatedAt' => date('Y-m-d H:i:s'),
        ], 'id', $ticketid);
        return $res;
    }

    public static function setSupporter($ticketId, $userId) {
        Panel::getDatabase()->update('tickets', [
            'assigned' => $userId
        ], 'id', $ticketId);
    }

    public static function close($ticketid) {
        Panel::getDatabase()->update('tickets', [
            'status' => 1
        ], 'id', $ticketid);
    }

    public static function reopen($ticketId) {
        Panel::getDatabase()->update('tickets', [
            'status' => 0
        ], 'id', $ticketId);
    }
}