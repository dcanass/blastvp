<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Objects\Event\EventManager;

class EventLog {

    const INFO    = 1;
    const WARNING = 2;
    const ERROR   = 3;

    public static function render() {
        $page   = $_GET['page'] ?? 1;
        $limit  = $_GET['limit'] ?? 20;
        $filter = $_GET['filter'] ?? "0";

        $start      = ($page - 1) * $limit;
        $totalItems = Panel::getDatabase()->countRows('logs');
        if ($filter) {
            $items = Panel::getDatabase()->custom_query("SELECT * FROM `logs` WHERE `level`=$filter ORDER BY id DESC LIMIT $limit OFFSET $start");
        } else {
            $items = Panel::getDatabase()->custom_query("SELECT * FROM `logs` ORDER BY id DESC LIMIT $limit OFFSET $start");
        }


        Panel::compile("_views/_pages/admin/event-log.html", array_merge([
            'items'  => $items->fetchAll(\PDO::FETCH_ASSOC),
            'page'   => $page,
            'filter' => $filter,
            'paging' => self::createLinks(2, 'mx-auto mt-4 pagination', $limit, $totalItems, (int) $page)
        ], Panel::getLanguage()->getPage('m_event_log')));
    }

    public static function log(
        $message,
        $level = EventLog::INFO
    ) {
        Panel::getDatabase()->insert('logs', [
            'level'   => $level,
            'message' => $message
        ]);
        EventManager::fire('event::create', ['level' => $level, 'message' => self::getMessage($message), 'rawMessage' => $message]);
    }

    public static function getMessage($type) {
        return Panel::getLanguage()->getPage('m_event_log')['error_messages'][$type];
    }

    public static function createLinks($links, $list_class, $pageSize, $total, $currentPage) {
        $last = ceil($total / $pageSize);

        $start = (($currentPage - $links) > 0) ? $currentPage - $links : 1;
        $end   = (($currentPage + $links) < $last) ? $currentPage + $links : $last;

        $html = '<ul class="' . $list_class . '">';

        $class = ($currentPage == 1) ? "page-item previous disabled" : "";
        $html .= '<li class="' . $class . '"><a class="page-link" href="?limit=' . $pageSize . '&page=' . ($currentPage - 1) . '">&laquo;</a></li>';

        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="?limit=' . $pageSize . '&page=1">1</a></li>';
        }

        for ($i = $start; $i <= $end; $i++) {
            $class = ($currentPage == $i) ? "active" : "";
            $html .= '<li class="page-item ' . $class . '"><a class="page-link" href="?limit=' . $pageSize . '&page=' . $i . '">' . $i . '</a></li>';
        }

        if ($end < $last) {
            $html .= '<li class="page-item "><a class="page-link" href="?limit=' . $pageSize . '&page=' . $last . '">' . $last . '</a></li>';
        }

        $class = ($currentPage == $last) ? "disabled" : "";
        $html .= '<li class="page-item  ' . $class . '"><a class="page-link" href="?limit=' . $pageSize . '&page=' . ($currentPage + 1) . '">&raquo;</a></li>';

        $html .= '</ul>';

        return $html;
    }
}