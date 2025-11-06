<?php
namespace Obfuscator\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Comment\Doc;

class DocblockCleaner extends NodeVisitorAbstract {
    private string $mode;

    public function __construct(string $mode = 'sanitize') {
        $this->mode = $mode;
    }

    private function setDoc(Node $node, ?string $text): void {
        // Use setDocComment when available but avoid passing null to it on versions that require Doc
        if (method_exists($node, 'setDocComment')) {
            if ($text === null || trim($text) === '') {
                // many php-parser versions accept null, but to be safe use an empty Doc
                $node->setDocComment(new Doc(''));
            } else {
                $node->setDocComment(new Doc($text));
            }
        } else {
            // fallback: set comments attribute
            $node->setAttribute('comments', $text ? [new Doc($text)] : []);
        }
    }

    private function sanitizeText(string $docText): string {
        $inner = preg_replace('#^/\*\*(.*)\*/#s', '$1', $docText);
        $lines = preg_split("/\r\n|\n|\r/", trim($inner));
        $descLines = [];
        foreach ($lines as $ln) {
            $ln = preg_replace('/^\s*\*\s?/', '', $ln);
            if (preg_match('/^\s*@/', $ln)) continue;
            if (trim($ln) !== '') {
                $descLines[] = trim($ln);
                if (count($descLines) >= 2) break;
            }
        }
        if (empty($descLines)) return '';
        $new = "/**\n";
        foreach ($descLines as $d) $new .= " * {$d}\n";
        $new .= " */";
        return $new;
    }

    public function enterNode(Node $node) {
        if (!($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Property
            || $node instanceof Node\Stmt\Namespace_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Const_
        )) {
            return;
        }

        $doc = $node->getDocComment();
        if ($doc === null) return;

        $text = $doc->getText();
        if ($this->mode === 'strip') {
            $this->setDoc($node, null);
            return;
        }

        $new = $this->sanitizeText($text);
        if ($new === '') $this->setDoc($node, null);
        else $this->setDoc($node, $new);
    }
}
