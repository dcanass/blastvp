<?php
namespace Objects\Event;

use Objects\Event\FlowComponents\AddField;
use Objects\Event\FlowComponents\CreateTicket;
use Objects\Event\FlowComponents\Enricher;
use Objects\Event\FlowComponents\Filter;
use Objects\Event\FlowComponents\IPAM;
use Objects\Event\FlowComponents\LoadRest;
use Objects\Event\FlowComponents\LoadTags;
use Objects\Event\FlowComponents\MathField;
use Objects\Event\FlowComponents\ModifyFirewall;
use Objects\Event\FlowComponents\ModifyServer;
use Objects\Event\FlowComponents\SendMail;
use Objects\Event\FlowComponents\Webhook;

class Node {

    private $id;
    private $name;
    private $data;
    /**
     * array of node inputs
     *
     * @var NodeInput[]
     */
    private $inputs = [];
    /**
     * array of node outputs
     *
     * @var NodeOutput[]
     */
    private $outputs = [];

    /**
     * raw node data
     *
     * @var [type]
     */
    private $raw;

    /**
     * initializes a new node.
     *
     * @param array $info
     */
    public function __construct($info) {
        $this->id   = $info['id'];
        $this->name = $info['name'];
        $this->data = $info['data'];

        $this->raw = $info;
    }

    /**
     * execute the action of the node
     *
     * @param array $parameters has the steps of the previous input
     * @return void
     */
    public function execute($parameters) {
        $next = $this->buildNextStep($parameters);

        // if $next at [0] is __index we had multiple outputs. The first element
        // indicates what output the data is send to, the second index contains the data

        foreach ($this->outputs as $k => $outList) {
            if (isset($next['__index'])) {
                $i = $next['__index'];
                if ($k == "output_$i") {
                    foreach ($outList as $out) {
                        $out->getConnectedNode()->execute($next[0]);
                    }
                }
            } else {
                foreach ($outList as $out) {
                    $out->getConnectedNode()->execute($next);
                }
            }
        }
    }

    public function buildNextStep($parameters): array {
        switch ($this->name) {
            /**
             * enrichers
             */
            case 'load-rest':
                return LoadRest::execute($this->data, $parameters);
            case 'load-data':
                return Enricher::execute($this->data, $parameters);
            case 'filter':
                return Filter::execute($this->data, $parameters);
            case 'add-field':
                return AddField::execute($this->data, $parameters);
            case 'math-field':
                return MathField::execute($this->data, $parameters);
            case 'load-tags':
                return LoadTags::execute($this->data, $parameters);
            case 'ipam':
                return IPAM::execute($this->data, $parameters);
            /**
             * actions
             */
            case 'nothing':
                return [];
            default:
                // search in registered entries
                foreach (EventManager::listActions() as $action) {
                    if ($action->getName() == $this->name) {
                        $action->getExecute()($this->data, $parameters);
                    }
                }

                break;
        }
        return $parameters;
    }

    public function connect(NodeRegistry $nodeRegistry) {
        foreach ($this->raw['inputs'] as $k => $v) {
            foreach ($v['connections'] as $connection) {
                $this->inputs[$k][] = new NodeInput($connection, $nodeRegistry);
            }
        }

        foreach ($this->raw['outputs'] as $k => $v) {
            foreach ($v['connections'] as $connection) {
                $this->outputs[$k][] = new NodeOutput($connection, $nodeRegistry);
            }
        }
    }

    /**
     * get a node input based on the id
     *
     * @param string $id
     * @return NodeInput
     */
    public function getInput(string $id): NodeInput {
        return $this->inputs[$id];
    }

    /**
     * get a node output based on the id
     *
     * @param string $id
     * @return NodeOutput
     */
    public function getOutput(string $id): NodeOutput {
        return $this->outputs[$id];
    }

    public function getName(): string {
        return $this->name;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getData():array{ 
        return $this->data;
    }
}