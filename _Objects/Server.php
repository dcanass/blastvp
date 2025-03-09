<?php
namespace Objects;

use Controllers\Panel;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Module\BaseModule\Controllers\Order;
use Module\BaseModule\Objects\ServerStatus;

class Server {

    /**
     * the amount of cores the server has
     *
     * @var int
     */
    public $cpu;
    /**
     * the date when the server was created
     *
     * @var string
     */
    public $createdAt;
    /**
     * the date when the server was deleted
     *
     * @var string
     */
    public $deletedAt;
    /**
     * this size of the boot-drive
     *
     * @var int
     */
    public $disk;
    /**
     * the hostname
     *
     * @var string
     */
    public $hostname;
    /**
     * the ID
     *
     * @var int
     */
    public $id;
    /**
     * the ipv4 IP
     *
     * @var string
     */
    public $ip;

    /**
     * the ipam range of the IP
     *
     * @var object
     */
    public $_ip;
    /**
     * the IPv6 ID
     *
     * @var int
     */
    public $ip6;
    /**
     * ipam range of ip6
     *
     * @var object
     */
    public $_ip6;
    /**
     * bool wether the server is shared with the owner or not
     *
     * @var bool
     */
    public $isShared;
    /**
     * the date when the next payment is due
     *
     * @var string
     */
    public $nextPayment;
    /**
     * the node the server is located on
     *
     * @var string
     */
    public $node;
    /**
     * @deprecated version
     * the online-status of the server
     *
     * @var int
     */
    public $online;
    /**
     * the OS of the server
     *
     * @var string
     */
    public $os;
    /**
     * the package - if exists - which the server was created from
     *
     * @var int
     */
    public $packageId;
    /**
     * date when the last payment reminder was sent
     *
     * @var string
     */
    public $paymentReminderSent;
    /**
     * the price of the server
     *
     * @var float
     */
    public $price;
    /**
     * the price - but formatted
     *
     * @var string
     */
    public $priceFormatted;
    /**
     * the ram of the server
     *
     * @var int
     */
    public $ram;
    /**
     * the status of the server
     *
     * @var \Module\BaseModule\Objects\ServerStatus
     */
    public $status;
    /**
     * Text representation of the server status
     *
     * @var string
     */
    public $_status;
    /**
     * the userid who owns the server
     *
     * @var int
     */
    public $userid;
    /**
     * the vmid of the server
     *
     * @var int
     */
    public $vmid;

    /**
     * when the server was cancelled
     *
     * @var string
     */
    public $cancelledAt;

    /**
     * create a new server-object instance
     *
     * @param object $dbEntry
     */
    function __construct($dbEntry) {
        // magically assign all variables from database to local
        foreach ($dbEntry as $k => $v) {
            $this->{$k} = $v;
        }
        $this->ip = $dbEntry->ip;
        if ($this->ip && !str_contains($this->ip, '.')) {
            $ip        = IPAMHelper::getIpv4ById($this->ip);
            $this->ip  = $ip->ip;
            $this->_ip = $ip;
        }

        if ($this->ip6) {
            $ip         = IPAMHelper::getIpv6ById($this->ip6);
            $this->ip6  = $ip->ip;
            $this->_ip6 = $ip;
        }

        $this->_status        = ServerStatus::getTextRepresentation($this->status);
        $this->priceFormatted = Formatters::formatBalance($this->price);
    }

    /**
     * return data in serializable format
     *
     * @return array
     */
    public function serialize() {
        $reflection = new \ReflectionClass($this);
        $vars       = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        $toReturn = [];

        foreach ($vars as $var) {
            $toReturn[$var->name] = $this->{$var->name};
        }

        return $toReturn;
    }
}