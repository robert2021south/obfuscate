<?php
namespace Obfuscator\Core;

use Obfuscator\Config\ConfigLoader;
use Obfuscator\Visitors\DocblockCleaner;
use Obfuscator\Visitors\PropertyNameObfuscator;
use Obfuscator\Visitors\WpFriendlyObfuscator;
use Obfuscator\Visitors\CallbackNameUpdater;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;

class ObfuscatorRunner {
    private array $cliDirs = [];
    private array $cliExcludes = [];

    public function __construct(private string $src, private string $out, private array $config = []) {
        $this->src = FileHelper::normalize($this->src);
        $this->out = FileHelper::normalize($this->out);
    }

    public function setCliDirs(array $dirs): void { $this->cliDirs = $dirs; }
    public function setCliExcludes(array $ex): void { $this->cliExcludes = $ex; }

    private function mergeCliOverrides(): void {
        if (!empty($this->cliDirs)) $this->config['dirs_to_obfuscate'] = $this->cliDirs;
        if (!empty($this->cliExcludes)) $this->config['exclude_files'] = array_merge($this->config['exclude_files'] ?? [], $this->cliExcludes);
    }

    private function normalizeExcludeFiles(array $list): array {
        $out = [];
        foreach ($list as $e) {
            if (!str_starts_with($e, '/') && !preg_match('/^[A-Za-z]:\\//', $e)) {
                $abs = $this->src . '/' . ltrim($e, '/\\');
            } else {
                $abs = $e;
            }
            $out[] = str_replace('\\', '/', $abs);
        }
        return $out;
    }

    private function shouldExcludePattern(string $filePath, array $patterns): bool {
        if (empty($patterns)) return false;
        $relative = substr(str_replace('\\','/',$filePath), strlen($this->src) + 1);
        foreach ($patterns as $p) {
            if (fnmatch($p, $relative)) return true;
        }
        return false;
    }

    private function shouldObfuscateDir(string $filePath, array $dirs): bool {
        if (empty($dirs)) return true;
        $normFile = str_replace('\\','/',$filePath);
        $root = $this->src;
        foreach ($dirs as $dir) {
            $dirNorm = str_replace('\\','/',$dir);
            if (!str_starts_with($dirNorm, '/')) $dirPath = rtrim($root,'/') . '/' . ltrim($dirNorm,'/');
            else $dirPath = $dirNorm;
            $dirPath = rtrim($dirPath, '/');
            if (str_starts_with($normFile, $dirPath . '/')) return true;
            if ($normFile === $dirPath) return true;
        }
        return false;
    }

    public function run(): void {
        echo "[Start] {$this->src} -> {$this->out}\n";
        $this->mergeCliOverrides();

        $excludeFilesNormalized = $this->normalizeExcludeFiles($this->config['exclude_files'] ?? []);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $printer = new Standard();

        $docMode = $this->config['docblock_mode'] ?? 'sanitize';
        $docCleaner = new DocblockCleaner($docMode);
        $propertyObf = new PropertyNameObfuscator();
        $wpObf = new WpFriendlyObfuscator($this->config);

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->src));
        foreach ($rii as $file) {
            if ($file->isDir()) continue;
            $full = str_replace('\\','/',$file->getRealPath());
            $ext = $file->getExtension();

            // Non-PHP: copy
            if ($ext !== 'php') {
                $rel = substr($full, strlen($this->src) + 1);
                $dest = $this->out . '/' . $rel;
                FileHelper::ensureDir($dest);
                copy($full, $dest);
                continue;
            }

            // 1) dir target check
            if (!$this->shouldObfuscateDir($full, $this->config['dirs_to_obfuscate'] ?? [])) {
                echo colorize("[Skip - dir not targeted] " . substr($full, strlen($this->src)+1) . "\n", 'yellow');
                continue;
            }

            // 2) exact exclude
            if (in_array($full, $excludeFilesNormalized, true)) {
                echo colorize("[Skip - exact exclude] " . substr($full, strlen($this->src)+1) . "\n", 'yellow');
                continue;
            }

            // 3) pattern exclude
            if ($this->shouldExcludePattern($full, $this->config['exclude_patterns'] ?? [])) {
                echo colorize("[Skip - pattern exclude] " . substr($full, strlen($this->src)+1) . "\n", 'yellow');
                continue;
            }

            $code = file_get_contents($full);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($docCleaner);
            $traverser->addVisitor($propertyObf);
            $traverser->addVisitor($wpObf);

            // snapshots before
            $beforeVars = $wpObf->varMap;
            $beforeFuncs = $wpObf->funcMap;
            $beforeMethods = $wpObf->methodMap;
            $beforeClasses = $wpObf->classMap;
            $beforeProps = $propertyObf->getMap();

            $relative = substr($full, strlen($this->src) + 1);
            try {
                $ast = $parser->parse($code);
                $ast = $traverser->traverse($ast);
                $new = $printer->prettyPrintFile($ast);
                $dest = $this->out . '/' . $relative;
                FileHelper::ensureDir($dest);
                file_put_contents($dest, $new);

                // snapshots after
                $afterVars = $wpObf->varMap;
                $afterFuncs = $wpObf->funcMap;
                $afterMethods = $wpObf->methodMap;
                $afterClasses = $wpObf->classMap;
                $afterProps = $propertyObf->getMap();

                $newVars = array_diff_key($afterVars, $beforeVars);
                $newFuncs = array_diff_key($afterFuncs, $beforeFuncs);
                $newMethods = array_diff_key($afterMethods, $beforeMethods);
                $newClasses = array_diff_key($afterClasses, $beforeClasses);
                $newProps = array_diff_key($afterProps, $beforeProps);

                echo colorize("[Obfuscated] {$relative}\n", 'green');
                if (!empty($newVars)) {
                    $pairs = []; foreach ($newVars as $o=>$m) $pairs[] = "{$o} -> {$m}";
                    echo colorize("  vars: " . implode(', ', $pairs) . PHP_EOL, 'yellow');
                }
                if (!empty($newFuncs)) {
                    $pairs = []; foreach ($newFuncs as $o=>$m) $pairs[] = "{$o} -> {$m}";
                    echo colorize("  functions: " . implode(', ', $pairs) . PHP_EOL, 'yellow');
                }
                if (!empty($newMethods)) {
                    $pairs = []; foreach ($newMethods as $o=>$m) $pairs[] = "{$o} -> {$m}";
                    echo colorize("  methods: " . implode(', ', $pairs) . PHP_EOL, 'yellow');
                }
                if (!empty($newProps)) {
                    $pairs = []; foreach ($newProps as $o=>$m) $pairs[] = "{$o} -> {$m}";
                    echo colorize("  properties: " . implode(', ', $pairs) . PHP_EOL, 'yellow');
                }
                if (!empty($newClasses)) {
                    $pairs = []; foreach ($newClasses as $o=>$m) $pairs[] = "{$o} -> {$m}";
                    echo colorize("  classes: " . implode(', ', $pairs) . PHP_EOL, 'yellow');
                }

            } catch (\Throwable $e) {
                echo colorize("[Error] {$relative}: " . $e->getMessage() . PHP_EOL, 'red');
                // copy original for debugging
                $dest = $this->out . '/' . $relative;
                FileHelper::ensureDir($dest);
                copy($full, $dest);
                echo colorize("[Copied original due to error] {$relative}\n", 'red');
            }
        }

        // save map
        $mapPath = $this->out . '/obfuscation-map.json';
        $wpObf->saveMapToFile($mapPath, $propertyObf->getMap());

        // Phase2: replace callbacks in output dir
        $map = json_decode(file_get_contents($mapPath), true);
        $funcMap = $map['functions'] ?? [];
        $methodMap = $map['methods'] ?? [];
        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new CallbackNameUpdater($funcMap, $methodMap));

        // normalize exclude list for output dir
        $excludeNormalizedOut = $this->normalizeExcludeFiles($this->config['exclude_files'] ?? []);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->out)) as $f) {
            if ($f->isDir()) continue;
            if ($f->getExtension() !== 'php') continue;
            $fp = str_replace('\\','/',$f->getRealPath());
            if (in_array($fp, $excludeNormalizedOut, true)) {
                echo colorize("[Skip - excluded] " . substr($fp, strlen($this->out)+1) . "\n", 'yellow');
                continue;
            }

            $relativeOut = substr($fp, strlen($this->out) + 1);
            $GLOBALS['CURRENT_OBFUSCATE_FILE'] = $relativeOut;
            try {
                $code = file_get_contents($fp);
                $ast = $parser->parse($code);
                $ast = $traverser2->traverse($ast);
                $new = $printer->prettyPrintFile($ast);
                file_put_contents($fp, $new);
                echo colorize("[Callback Updated] {$relativeOut}\n", 'green');
            } catch (\Throwable $e) {
                echo colorize("[Phase2 Error] {$relativeOut}: " . $e->getMessage() . PHP_EOL, 'red');
            } finally {
                $GLOBALS['CURRENT_OBFUSCATE_FILE'] = '';
            }
        }

        echo colorize("\n[Done] All stages completed.\n", 'yellow');
    }
}

// small helper for color used in runner
function colorize(string $text, string $color): string {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}
