<?php

namespace Objects;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;

class Invoice {

    public $id;
    public $amount;
    public $type;
    public $userid;
    public $createdAt;
    public $done;
    public $descriptor;
    public $_type;
    public $_amount;
    public $_createdAt;
    public $_vat;

    const BALANCE = 1;
    const PAYMENT = 2;
    const CREDIT  = 3;

    public function __construct($data) {
        $this->id         = $data->id;
        $this->amount     = $data->amount;
        $this->type       = $data->type;
        $this->userid     = $data->userid;
        $this->createdAt  = $data->createdAt;
        $this->done       = $data->done;
        $this->descriptor = $data->descriptor;

        $this->_amount    = Formatters::formatBalance($this->amount);
        $this->_type      = Panel::getLanguage()->get('invoices', "m_type_" . $this->type);
        $this->_createdAt = Formatters::formatDateAbsolute($this->createdAt);

        $this->_vat = Formatters::formatBalance($this->amount / 100 * Settings::getConfigEntry("INVOICE_VAT"));
    }

    public function getFormattedAmount() {
        return Formatters::formatBalance($this->amount);
    }

    /**
     * returns the next free invoice string
     *
     * @return string
     */
    public static function getNextId() {
        $invoiceId = Panel::getDatabase()->custom_query("SELECT * FROM invoices ORDER BY id DESC LIMIT 1")->fetchAll(\PDO::FETCH_OBJ);
        if ($invoiceId) {
            $invoiceId = $invoiceId[0]->id + 1;
        } else {
            $invoiceId = 1;
        }
        return 'R-' . date('Y-m-d') . '-' . $invoiceId;
    }



    /**
     * Get the value of descriptor
     */
    public function getDescriptor() {
        return $this->descriptor;
    }

    /**
     * Set the value of descriptor
     *
     * @return  self
     */
    public function setDescriptor($descriptor) {
        $this->descriptor = $descriptor;

        return $this;
    }
}
