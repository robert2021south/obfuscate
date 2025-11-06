<?php
namespace Obfuscator\Utils;

class FileHelper {
    public static function normalize(string $p): string {
        return str_replace('\\', '/', rtrim($p, '/\\'));
    }
    public static function ensureDir(string $filePath): void {
        $d = dirname($filePath);
        if (!is_dir($d)) @mkdir($d, 0777, true);
    }
}
