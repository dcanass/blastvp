<?php
namespace Objects\Event;

class NodeInput {


    private $connectedNode;
    private $connectedPort;


    public function __construct($info, NodeRegistry $registry) {
        $this->connectedNode = $registry->getNode($info['node']);
        $this->connectedPort = $info['input'];
    }

    /**
     * get the connected port for the output
     *
     * @return NodeOutput
     */
    public function getConnectedPort() {
        return $this->connectedNode->getOutput($this->connectedPort);
    }

    /**
     * return the connected node
     *
     * @return Node
     */
    public function getConnectedNode(): Node {
        return $this->connectedNode;
    }

}