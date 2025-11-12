<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class PropertyNameObfuscator extends NodeVisitorAbstract {
    private array $propMap = [];
    private int $counter = 0;
    private array $whitelist = [];

    /**
     * 构造：$config 可以是完整配置数组或直接的 properties 列表
     *
     * @param array $configOrList
     */
    public function __construct(array $configOrList = []) {
        // 支持两种情况：传入完整 config 或直接传入 properties 列表
        if ($this->isAssocArray($configOrList)) {
            $props = $configOrList['properties'] ?? [];
        } else {
            $props = $configOrList;
        }

        // 规范化：移除前导 '$'，并去重
        $this->whitelist = array_values(array_unique(array_map(
            fn($n) => ltrim((string)$n, '$'),
            $props
        )));
    }

    private function isAssocArray(array $a): bool {
        if ($a === []) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }

    private function isPropertyItem($prop): bool {
        return is_object($prop) && (property_exists($prop, 'name') || isset($prop->name));
    }

    public function enterNode(Node $node) {
        // 处理属性声明 (只混淆非 public 的属性)
        if ($node instanceof Node\Stmt\Property && !$node->isPublic() && !empty($node->props)) {
            foreach ($node->props as $prop) {
                if (!$this->isPropertyItem($prop)) continue;

                $old = $prop->name->toString();

                // 白名单跳过
                if (in_array($old, $this->whitelist, true)) continue;

                if (!isset($this->propMap[$old])) {
                    $this->propMap[$old] = '__p' . base_convert($this->counter++, 10, 36);
                }

                $prop->name = new Node\VarLikeIdentifier($this->propMap[$old]);
            }
        }

        // 对象属性访问 $this->foo
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $n = $node->name->toString();
            if (isset($this->propMap[$n])) $node->name = new Node\Identifier($this->propMap[$n]);
        }

        // 静态属性访问 self::$foo
        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $n = $node->name->toString();
            if (isset($this->propMap[$n])) $node->name = new Node\VarLikeIdentifier($this->propMap[$n]);
        }
    }

    public function getMap(): array {
        return $this->propMap;
    }
}