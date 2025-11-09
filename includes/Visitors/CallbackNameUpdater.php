<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

class CallbackNameUpdater extends NodeVisitorAbstract {
    private array $funcMap;
    private array $methodMap;
    private ?string $currentClassName = null;

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
        // 记录当前类
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClassName = $node->name?->toString();
        }

        // --- 处理普通函数回调（add_action/add_filter等） ---
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Name) {
            $fname = $node->name->toString();

            // add_action / add_filter array 或 string callback
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

            // register_rest_route callback
            if ($fname === 'register_rest_route' && isset($node->args[2])) {
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
        }

        // [$this, 'method'] 数组回调
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

        // --- 新增处理普通方法调用 $this->method() ---
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $methodName = $node->name->name;
            $mapped = null;

            // 尝试用当前类名匹配 methodMap
            if ($this->currentClassName && isset($this->methodMap[$methodName])) {
                $mapped = $this->methodMap[$methodName];
            }

            // 直接裸方法名匹配
            if (!$mapped && isset($this->methodMap[$methodName])) {
                $mapped = $this->methodMap[$methodName];
            }

            if ($mapped) {
                $node->name = new Identifier($mapped);
                $this->logReplace('MethodCall', $methodName, $mapped);
            }
        }

        // --- 新增处理静态方法调用 Class::method() ---
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $methodName = $node->name->name;
            $className = $node->class instanceof Name ? $node->class->toString() : null;
            $mapped = null;

            // Class::method 映射
            if ($className && isset($this->methodMap[$methodName])) {
                $mapped = $this->methodMap[$methodName];
            }

            // 裸方法名映射
            if (!$mapped && isset($this->methodMap[$methodName])) {
                $mapped = $this->methodMap[$methodName];
            }

            if ($mapped) {
                $node->name = new Identifier($mapped);
                $this->logReplace('StaticCall', $methodName, $mapped);
            }
        }

        return null;
    }

    public function leaveNode(Node $node) {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClassName = null;
        }
        return null;
    }
}
