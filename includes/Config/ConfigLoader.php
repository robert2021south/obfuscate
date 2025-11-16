<?php
namespace Obfuscator\Config;

class ConfigLoader {
    public static function load(string $path): array {
        $json = @file_get_contents($path);
        if ($json === false) return [];
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['whitelist'])) return [];

        $w = $data['whitelist'];

        // 处理 globals，去掉 $ 前缀
        $globals = array_map(fn($name) => ltrim($name, '$'), $w['globals'] ?? []);

        // 默认关键全局变量
        $defaultGlobals = ['GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV'];
        $globals = array_unique(array_merge($defaultGlobals, $globals));

        // 移除 'this'，$this 在 AST 中特殊处理
        $globals = array_filter($globals, fn($name) => $name !== 'this');

        // 处理 variables，去掉 $ 前缀
        $variables = array_map(fn($name) => ltrim($name,'$'), $w['variables'] ?? []);

        // 可以加入默认测试用变量，防止误混淆
        $defaultVars = ['forceStandaloneForTests','mock_options','mockIsValid'];
        $variables = array_unique(array_merge($defaultVars, $variables));

        return [
            'dirs_to_obfuscate' => $w['dirs_to_obfuscate'] ?? [],
            'exclude_files' => $w['exclude_files'] ?? [],
            'exclude_patterns' => $w['exclude_patterns'] ?? [],
            'functions' => array_merge($w['functions'] ?? []),
            'classes' => array_merge($w['classes'] ?? []),
            'properties' => array_map(fn($name) => ltrim($name,'$'), $w['properties'] ?? []),
            'methods' => $w['methods'] ?? [],
            'globals' => $globals,
            'variables' => $variables,
            'constants' => $w['constants'] ?? [],
            'obfuscate_constants' => $w['obfuscate_constants'] ?? false,  // 默认不混淆常量
        ];
    }
}
