<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class PropertyNameObfuscator extends NodeVisitorAbstract {
    private array $propMap = [];
    private int $counter = 0;

    private function isPropertyItem($prop): bool {
        // safe heuristic: most property item nodes have ->name
        return is_object($prop) && (property_exists($prop, 'name') || isset($prop->name));
    }

    public function enterNode(Node $node) {
        if ($node instanceof Node\Stmt\Property && !$node->isPublic() && !empty($node->props)) {
            foreach ($node->props as $prop) {
                if (!$this->isPropertyItem($prop)) continue;
                $old = $prop->name->toString();
                if (!isset($this->propMap[$old])) {
                    $this->propMap[$old] = '__p' . base_convert($this->counter++, 10, 36);
                }
                $prop->name = new Node\VarLikeIdentifier($this->propMap[$old]);
            }
        }

        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $n = $node->name->toString();
            if (isset($this->propMap[$n])) $node->name = new Node\Identifier($this->propMap[$n]);
        }

        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $n = $node->name->toString();
            if (isset($this->propMap[$n])) $node->name = new Node\VarLikeIdentifier($this->propMap[$n]);
        }
    }

    public function getMap(): array {
        return $this->propMap;
    }
}
