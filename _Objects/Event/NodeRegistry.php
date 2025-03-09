<?php
namespace Objects\Event;

/**
 * the NodeRegistry holds all nodes that are registered and their connections to avoid duplicates
 */
class NodeRegistry {


    /**
     * @var Node[]
     */
    private $nodes = [];

    public function addNode($id, Node $node) {
        $this->nodes[$id] = $node;
    }

    public function removeNode($id) {
        unset($this->nodes[$id]);
    }

    /**
     * returns a single node
     *
     * @param string $id
     * @return Node
     */
    public function getNode(string $id): Node {
        return $this->nodes[$id];
    }

    public function connectAll() {
        foreach ($this->nodes as $node) {
            $node->connect($this);
        }
    }

    /**
     * return all nodes
     *
     * @return Node[]
     */
    public function getNodes() {
        return $this->nodes;
    }
}