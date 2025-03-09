<?php

namespace Objects;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Event\TemplateReplacer;

class Notification {

    private $id;
    private $email;
    private $emailTries;
    private $type;
    private $createdAt;
    private $hasRead = 0;
    private $userId;
    private $meta;

    const DEFAULT_EMAIL_RETRIES = 3;

    const TYPE_SERVERS = "servers";
    const TYPE_ACCOUNT = "account";
    const TYPE_TICKETS = "tickets";

    public function __construct($id = null, $email = null, $emailTries = null, $type = null, $meta = null, $createdAt = null, $hasRead = null) {
        $this->id         = $id;
        $this->email      = $email;
        $this->emailTries = $emailTries;
        $this->type       = $type;
        $this->meta       = $meta;
        $this->createdAt  = $createdAt;
        $this->hasRead    = $hasRead;
    }

    public static function loadFromRes($res) {
        return new self($res->id, $res->email, $res->emailTries, $res->notificationType, $res->meta, $res->createdAt, $res->hasRead);
    }


    public function save() {
        return Panel::getDatabase()->insert('notifications', [
            'userId'           => $this->userId,
            'email'            => $this->email,
            'emailTries'       => $this->emailTries ?? self::DEFAULT_EMAIL_RETRIES,
            'notificationType' => $this->type,
            'meta'             => $this->meta,
            'hasRead'          => $this->hasRead ?? 0
        ]);
    }

    public function getText() {
        $text = "";
        switch ($this->type) {
            case "account":
                switch ($this->meta) {

                    case "password_reset":
                        $text = Panel::getLanguage()->get('notifications', 'password_changed');
                        break;
                    case "account_information":
                        $text = Panel::getLanguage()->get('notifications', 'account_updated');
                        break;
                }
                break;
            case "tickets":
                switch ($this->meta) {
                    case (preg_match("/ticket_created_(\d+)/", $this->meta, $ticketId) ? true : false):
                        $ticket = Panel::getDatabase()->fetch_single_row("tickets", 'id', $ticketId[1], \PDO::FETCH_ASSOC);
                        if (!$ticket) {
                            $text = "unknown";
                            break;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'ticket_created'), $ticket);
                        break;

                    case (preg_match("/ticket_answer_(\d+)/", $this->meta, $ticketId) ? true : false):
                        $ticket = Panel::getDatabase()->fetch_single_row("tickets", 'id', $ticketId[1], \PDO::FETCH_ASSOC);
                        if (!$ticket) {
                            $text = "unknown";
                            break;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'ticket_answer'), $ticket);
                        break;

                    case (preg_match("/ticket_closed_(\d+)/", $this->meta, $ticketId) ? true : false):
                        $ticket = Panel::getDatabase()->fetch_single_row("tickets", 'id', $ticketId[1], \PDO::FETCH_ASSOC);
                        if (!$ticket) {
                            $text = "unknown";
                            break;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'ticket_closed'), $ticket);
                        break;

                    case (preg_match("/ticket_reopened_(\d+)/", $this->meta, $ticketId) ? true : false):
                        $ticket = Panel::getDatabase()->fetch_single_row("tickets", 'id', $ticketId[1], \PDO::FETCH_ASSOC);
                        if (!$ticket) {
                            $text = "unknown";
                            break;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'ticket_reopened'), $ticket);
                        break;
                }
                break;
            case "servers":
                switch ($this->meta) {
                    case (preg_match("/server_started_(\d+)/", $this->meta, $serverId) ? true : false):
                        $server = Panel::getDatabase()->fetch_single_row("servers", 'id', $serverId[1], \PDO::FETCH_ASSOC);
                        if (!$server) {
                            $text = "unknown";
                            return;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'server_started'), $server);
                        break;

                    case (preg_match("/server_stopped_(\d+)/", $this->meta, $serverId) ? true : false):
                        $server = Panel::getDatabase()->fetch_single_row("servers", 'id', $serverId[1], \PDO::FETCH_ASSOC);
                        if (!$server) {
                            $text = "unknown";
                            return;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'server_stopped'), $server);
                        break;

                    case (preg_match("/server_restarted_(\d+)/", $this->meta, $serverId) ? true : false):
                        $server = Panel::getDatabase()->fetch_single_row("servers", 'id', $serverId[1], \PDO::FETCH_ASSOC);
                        if (!$server) {
                            $text = "unknown";
                            return;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'server_restarted'), $server);
                        break;

                    case (preg_match("/server_deleted_(\d+)/", $this->meta, $serverId) ? true : false):
                        $server = Panel::getDatabase()->fetch_single_row("servers", 'id', $serverId[1], \PDO::FETCH_ASSOC);
                        if (!$server) {
                            $text = "unknown";
                            return;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'server_deleted'), $server);
                        break;

                    case (preg_match("/server_rebuilded_(\d+)/", $this->meta, $serverId) ? true : false):
                        $server = Panel::getDatabase()->fetch_single_row("servers", 'id', $serverId[1], \PDO::FETCH_ASSOC);
                        if (!$server) {
                            $text = "unknown";
                            return;
                        }
                        $text = TemplateReplacer::replaceAll(Panel::getLanguage()->get('notifications', 'server_rebuild'), $server);
                        break;
                }
                break;
        }

        return $text;
    }

    /**
     * Get the value of id
     *
     * @return  mixed
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Set the value of id
     *
     * @param   mixed  $id  
     *
     * @return  self
     */
    public function setId($id) {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the value of email
     *
     * @return  mixed
     */
    public function getEmail() {
        return $this->email;
    }

    /**
     * Set the value of email
     *
     * @param   mixed  $email  
     *
     * @return  self
     */
    public function setEmail($email) {
        $this->email = $email;

        return $this;
    }

    /**
     * Get the value of emailTries
     *
     * @return  mixed
     */
    public function getEmailTries() {
        return $this->emailTries;
    }

    /**
     * Set the value of emailTries
     *
     * @param   mixed  $emailTries  
     *
     * @return  self
     */
    public function setEmailTries($emailTries) {
        $this->emailTries = $emailTries;

        return $this;
    }

    /**
     * Get the value of type
     *
     * @return  mixed
     */
    public function getType() {
        return $this->type;
    }

    /**
     * Set the value of type
     *
     * @param   mixed  $type  
     *
     * @return  self
     */
    public function setType($type) {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the value of createdAt
     *
     * @return  mixed
     */
    public function getCreatedAt() {
        return $this->createdAt;
    }

    /**
     * Set the value of createdAt
     *
     * @param   mixed  $createdAt  
     *
     * @return  self
     */
    public function setCreatedAt($createdAt) {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Get the value of hasRead
     *
     * @return  mixed
     */
    public function getHasRead() {
        return $this->hasRead;
    }

    /**
     * Set the value of hasRead
     *
     * @param   mixed  $hasRead  
     *
     * @return  self
     */
    public function setHasRead($hasRead) {
        $this->hasRead = $hasRead;

        return $this;
    }

    /**
     * Get the value of userId
     *
     * @return  mixed
     */
    public function getUserId() {
        return $this->userId;
    }

    /**
     * Set the value of userId
     *
     * @param   mixed  $userId  
     *
     * @return  self
     */
    public function setUserId($userId) {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Get the value of meta
     *
     * @return  mixed
     */
    public function getMeta() {
        return $this->meta;
    }

    /**
     * Set the value of meta
     *
     * @param   mixed  $meta  
     *
     * @return  self
     */
    public function setMeta($meta) {
        $this->meta = $meta;

        return $this;
    }
}