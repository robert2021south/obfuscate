<?php
namespace Obfuscator\Config;

class ConfigLoader {
    public static function load(string $path): array {
        $json = @file_get_contents($path);
        if ($json === false) return [];
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['whitelist'])) return [];
        $w = $data['whitelist'];
        return [
            'functions' => array_merge($w['functions'] ?? []),
            'classes' => array_merge($w['classes'] ?? []),
            'properties' => array_merge($w['properties'] ?? []),
            'methods' => array_merge($w['methods'] ?? []),
            'globals' => array_merge($w['globals'] ?? []),
            'variables' => array_merge($w['variables'] ?? []),
            'constants' => array_merge($w['constants'] ?? []),
            'exclude_patterns' => $w['exclude_patterns'] ?? [],
            'exclude_files' => $w['exclude_files'] ?? [],
            'dirs_to_obfuscate' => $w['dirs_to_obfuscate'] ?? [],
            'docblock_mode' => $w['docblock_mode'] ?? 'sanitize',
        ];
    }
}
