<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class CallbackNameUpdater extends NodeVisitorAbstract {
    private array $funcMap;
    private array $methodMap;

    public function __construct(array $funcMap = [], array $methodMap = []) {
        $this->funcMap = $funcMap;
        $this->methodMap = $methodMap;
    }

    private function currentFile(): string {
        return $GLOBALS['CURRENT_OBFUSCATE_FILE'] ?? '(unknown)';
    }

    private function logReplace(string $type, string $orig, string $mapped): void {
        $file = $this->currentFile();
        echo "[Callback Replaced] {$file} : {$type} '{$orig}' -> '{$mapped}'" . PHP_EOL;
    }

    public function enterNode(Node $node) {
        // add_action/add_filter
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $fname = $node->name->toString();
            if (in_array($fname, ['add_action','add_filter'], true) && isset($node->args[1])) {
                $arg = $node->args[1]->value;
                if ($arg instanceof Node\Scalar\String_) {
                    $orig = $arg->value;
                    if (isset($this->funcMap[$orig])) {
                        $arg->value = $this->funcMap[$orig];
                        $this->logReplace($fname . " string callback", $orig, $this->funcMap[$orig]);
                    }
                }
                if ($arg instanceof Node\Expr\Array_ && count($arg->items) >= 2) {
                    $second = $arg->items[1]->value;
                    if ($second instanceof Node\Scalar\String_) {
                        $origMethod = $second->value;
                        if (isset($this->methodMap[$origMethod])) {
                            $mapped = $this->methodMap[$origMethod];
                            $second->value = $mapped;
                            $this->logReplace($fname . " array method", $origMethod, $mapped);
                        }
                    }
                }
            }
        }

        // register_rest_route callback handling
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && $node->name->toString() === 'register_rest_route' && isset($node->args[2])) {
            $arg = $node->args[2]->value;
            if ($arg instanceof Node\Expr\Array_) {
                foreach ($arg->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_
                        && $item->key->value === 'callback'
                        && $item->value instanceof Node\Scalar\String_) {
                        $orig = $item->value->value;
                        if (isset($this->funcMap[$orig])) {
                            $mapped = $this->funcMap[$orig];
                            $item->value->value = $mapped;
                            $this->logReplace('register_rest_route callback', $orig, $mapped);
                        }
                    }
                }
            }
        }

        // [$this, 'method'] pattern
        if ($node instanceof Node\Expr\Array_ && count($node->items) === 2) {
            $first = $node->items[0]->value ?? null;
            $second = $node->items[1]->value ?? null;
            if ($first instanceof Node\Expr\Variable && $first->name === 'this' && $second instanceof Node\Scalar\String_) {
                $orig = $second->value;
                if (isset($this->methodMap[$orig])) {
                    $mapped = $this->methodMap[$orig];
                    $second->value = $mapped;
                    $this->logReplace("[\$this,'method']", $orig, $mapped);
                }
            }
        }
    }
}
