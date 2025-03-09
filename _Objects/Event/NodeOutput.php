<?php
namespace Objects\Event;

class NodeOutput {


    private $connectedNode;
    private $connectedPort;


    public function __construct($info, private NodeRegistry $registry) {
        $this->connectedNode = $this->registry->getNode($info['node']);
        $this->connectedPort = $info['output'];
    }


    /**
     * get the connected port for the input
     *
     * @return NodeInput
     */
    public function getConnectedPort() {
        return $this->connectedNode->getInput($this->connectedPort);
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