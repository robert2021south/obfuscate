<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class WpFriendlyObfuscator extends NodeVisitorAbstract {
    public array $varMap = [];
    public array $funcMap = [];
    public array $classMap = [];
    public array $methodMap = [];

    private int $varCounter = 0;
    private int $funcCounter = 0;
    private int $methodCounter = 0;
    private array $config;

    public function __construct(array $config = []) {
        $this->config = $config;
    }

    private function gen(string $prefix, int $counter): string {
        return "__{$prefix}" . base_convert($counter, 10, 36);
    }

    public function enterNode(Node $node) {
        // variables
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if ($node->hasAttribute('already_obfuscated')) return;
            if (in_array($node->name, $this->config['globals'] ?? ['this','GLOBALS','_POST','_GET','_REQUEST','_SERVER','_FILES','_COOKIE','_SESSION'], true)) {
                return;
            }
            if (!isset($this->varMap[$node->name])) {
                $this->varMap[$node->name] = $this->gen('v', $this->varCounter++);
            }
            $node->name = $this->varMap[$node->name];
            $node->setAttribute('already_obfuscated', true);
        }

        // function defs
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['functions'] ?? [], true)) {
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = $this->gen('f', $this->funcCounter++);
                }
                $node->name->name = $this->funcMap[$name];
            }
        }

        // function calls
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $n = $node->name->toString();
            if (isset($this->funcMap[$n])) {
                $node->name = new Node\Name($this->funcMap[$n]);
            }
        }

        // class names: record but DO NOT change (for PSR-4 / autoload safety)
        if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
            $n = $node->name->name;
            if (!isset($this->classMap[$n])) $this->classMap[$n] = $n;
        }

        // class methods
        if ($node instanceof Node\Stmt\ClassMethod) {
            $name = $node->name->name;
            if (!in_array($name, $this->config['methods'] ?? [], true)) {
                if (!isset($this->methodMap[$name])) {
                    $this->methodMap[$name] = $this->gen('m', $this->methodCounter++);
                }
                $node->name->name = $this->methodMap[$name];
            }
        }

        // method calls
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $mn = $node->name->name;
            if (isset($this->methodMap[$mn])) $node->name->name = $this->methodMap[$mn];
        }
    }

    public function saveMapToFile(string $filePath, array $propMap = []): void {
        $map = [
            'variables' => $this->varMap,
            'functions' => $this->funcMap,
            'classes' => $this->classMap,
            'methods' => $this->methodMap,
            'properties' => $propMap,
        ];
        $dir = dirname($filePath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        file_put_contents($filePath, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
