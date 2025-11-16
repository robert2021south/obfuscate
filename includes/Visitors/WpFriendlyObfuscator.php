<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class WpFriendlyObfuscator extends NodeVisitorAbstract {
    public array $varMap = [];
    public array $funcMap = [];
    public array $classMap = [];
    public array $methodMap = [];
    public array $constMap = [];

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
            // $this must never be obfuscated
            if ($node->name === 'this') {
                return;
            }

            if ($node->hasAttribute('already_obfuscated')) return;

            // globals whitelist
            if (in_array($node->name, $this->config['globals'] ?? [], true)) {
                return;
            }
            if (!isset($this->varMap[$node->name])) {
                $this->varMap[$node->name] = $this->gen('v', $this->varCounter++);
            }
            $node->name = $this->varMap[$node->name];
            $node->setAttribute('already_obfuscated', true);
        }

        // constants (global const X = ...)
        if ($node instanceof Node\Stmt\Const_) {

            // ⭐ 添加这一行：默认不混淆常量
            if (!($this->config['obfuscate_constants'] ?? false)) {
                return;
            }
            foreach ($node->consts as $const) {
                $name = $const->name->name;
                if (!in_array($name, $this->config['constants'] ?? [], true)) {
                    if (!isset($this->constMap[$name])) {
                        $this->constMap[$name] = $this->gen('c', count($this->constMap));
                    }
                    $const->name->name = $this->constMap[$name];
                }
            }
        }

        // class constants (class Foo { const BAR = 1; })
        if ($node instanceof Node\Stmt\ClassConst) {

            // ⭐ 添加这一行：默认不混淆
            if (!($this->config['obfuscate_constants'] ?? false)) {
                return;
            }
            foreach ($node->consts as $const) {
                $name = $const->name->name;

                if (in_array($name, $this->config['constants'] ?? [], true)) {
                    continue; // 白名单：跳过，不生成、不替换
                }

                if (!isset($this->constMap[$name])) {
                    $this->constMap[$name] = $this->gen('c', count($this->constMap));
                }
                $const->name->name = $this->constMap[$name];
            }
        }

        // define('CONST_NAME', value)
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'define') {
            // ⭐ 添加这一行：默认不混淆
            if (!($this->config['obfuscate_constants'] ?? false)) {
                return;
            }

            if (isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
                $name = $node->args[0]->value->value;
                if (!in_array($name, $this->config['constants'] ?? [], true)) {
                    if (!isset($this->constMap[$name])) {
                        $this->constMap[$name] = $this->gen('c', count($this->constMap));
                    }
                    $node->args[0]->value->value = $this->constMap[$name];
                }
            }
        }

        // constant usage (MY_CONST)
        if ($node instanceof Node\Expr\ConstFetch && $node->name instanceof Node\Name) {
            // ⭐ 添加这一行：默认不混淆
            if (!($this->config['obfuscate_constants'] ?? false)) {
                return;
            }
            $name = $node->name->toString();
            if (isset($this->constMap[$name])) {
                $node->name = new Node\Name($this->constMap[$name]);
            }
        }

        // class constant usage (Foo::BAR)
        if ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
            // ⭐ 添加这一行：默认不混淆
            if (!($this->config['obfuscate_constants'] ?? false)) {
                return;
            }
            $name = $node->name->name;
            if (isset($this->constMap[$name])) {
                $node->name->name = $this->constMap[$name];
            }
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
            'constants' => $this->constMap,
        ];
        $dir = dirname($filePath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        file_put_contents($filePath, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
