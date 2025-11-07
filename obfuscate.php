#!/usr/bin/env php
<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','on');

require __DIR__ . '/vendor/autoload.php';

use Obfuscator\Config\ConfigLoader;
use Obfuscator\Core\ObfuscatorRunner;
use Obfuscator\Helpers\StripComment;
use Random\RandomException;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$src = $argv[1] ?? null;
$out = $argv[2] ?? null;
if (!$src || !$out) {
    fwrite(STDERR, "Usage: php obfuscate.php <source_dir> <target_dir> [--dirs=\"dir1,dir2\"] [--exclude=\"file1,file2\"]\n");
    exit(1);
}

// 默认将 runner 要用的源目录设为原始 src
try {
    $runnerSrc = StripComment::run($src);
} catch (\Throwable $e) {
    fwrite(STDERR, "[StripComment] Failed: {$e->getMessage()}\n");
    $runnerSrc = $src;
}

$configPath = __DIR__ . '/obfuscate-config.json';
$config = [];
if (file_exists($configPath)) {
    $config = ConfigLoader::load($configPath);
}

$runner = new ObfuscatorRunner($runnerSrc, $out, $config);

// parse CLI overrides
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dirs=')) {
        $dirs = array_map('trim', explode(',', substr($arg, 7)));
        $runner->setCliDirs($dirs);
    }
    if (str_starts_with($arg, '--exclude=')) {
        $ex = array_map('trim', explode(',', substr($arg, 10)));
        $runner->setCliExcludes($ex);
    }
}

$runner->run();
