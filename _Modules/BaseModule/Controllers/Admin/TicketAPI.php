<?php
namespace Module\BaseModule\Controllers\Admin;

use Controllers\Panel;
use Doctrine\DBAL\ParameterType;
use Objects\Ticket;

class TicketAPI {
    public static function getTickets() {
        $b      = Panel::getRequestInput();
        $page   = $b['page'];
        $size   = 10;
        $status = $b['status'];

        if (isset($b['userId'])) {
            $qry = "SELECT * FROM tickets WHERE userid=? ORDER BY updatedAt DESC";
            switch ($status) {
                case "0":
                    $qry = "SELECT * FROM tickets WHERE userid=? AND status=0 ORDER BY updatedAt DESC";
                    break;
                case "1":
                    $qry = "SELECT * FROM tickets WHERE userid=? AND status=1 ORDER BY updatedAt DESC";
                    break;
                default:
                    $qry = "SELECT * FROM tickets WHERE userid=? ORDER BY updatedAt DESC";
                    break;
            }

            $invoiceCount = Panel::getDatabase()->custom_query($qry, ['userid' => $b['userId']])->rowCount();
            return ['data' => array_map(function ($e) {
                return new Ticket($e);
            },
                Panel::getDatabase()->custom_query($qry . " LIMIT ?, ?", [
                    'userid' => $b['userId'],
                    'skip'   => ($page - 1) * $size,
                    'limit'  => $size,
                ], ['userid' => ParameterType::STRING,
                    'skip'   => ParameterType::INTEGER,
                    'limit'  => ParameterType::INTEGER
                ])->fetchAll()),
                'paging' => [
                    'count'       => $invoiceCount,
                    'pageSize'    => $size,
                    'currentPage' => $page
                ]];
        }

        return ['data' => []];
    }
}