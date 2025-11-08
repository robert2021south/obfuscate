<?php

declare(strict_types=1);

namespace Obfuscator\Helpers;

use Random\RandomException;

/**
 * StripComment
 *
 * 责任：
 *  - 将源目录复制到临时目录（可选），
 *  - 在临时目录上使用 tokenizer 去除 PHP 注释
 *  -支持 exclude、backup、dry - run 等选项,
 *  -返回用于后续混淆的路径（如果没有启用 strip 则返回原目录）
 *
 * 使用：
 * $tmpOrSrc = \App\Helper\StripComment::run($src, [
 *       'strip'    => true,
 *       'exclude'  => ['vendor', 'node_modules'],
 *       'backup'   => true,
 *       'dry_run'  => false,
 *       'verbose'  => true,
 *   ]);
 * ✅ 方式2：独立命令行工具使用
 * php includes/Helper/StripComment.php ./src
 * php includes/Helper/StripComment.php /path/to/any/php/project
 */
class StripComment
{
    /**
     * 主入口：进行预处理并返回处理后要交给 Obfuscator 的源路径
     *
     * @param string $src 原始源目录
     * @param array $opts 可选参数：
     *   - strip: bool (默认 true) 是否去注释
     *   - exclude: array 相对路径 substring 排除列表
     *   - backup: bool 是否在临时目录对修改的文件生成 .bak
     *   - dry_run: bool 仅模拟（不写盘）
     *   - verbose: bool 输出日志
     * @return string 处理后用于混淆的源目录（可能是临时目录或原目录）
     * @throws RandomException
     */
    public static function run(string $src, array $opts = []): string
    {
        $strip = $opts['strip'] ?? true;
        $excludes = $opts['exclude'] ?? [];
        $backup = $opts['backup'] ?? false;
        $dryRun = $opts['dry_run'] ?? false;
        $verbose = $opts['verbose'] ?? false;

        if (!$strip) {
            if ($verbose) echo "[StripComment] strip disabled, returning original src: $src\n";
            return $src;
        }

        if (!is_dir($src)) {
            throw new \InvalidArgumentException("Source directory not found: $src");
        }

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'obf_tmp_' . bin2hex(random_bytes(6));
        if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
            throw new \RuntimeException("Failed to create temp dir: $tmp");
        }
        if ($verbose) echo "[StripComment] created temp dir: $tmp\n";

        // copy source -> tmp (preserve relative structure; skip excludes)
        self::copyRecursive($src, $tmp, $excludes, $verbose);

        // preprocess: strip comments on tmp
        self::preprocessStripComments($tmp, $excludes, $backup, $dryRun, $verbose);

        if ($verbose) {
            echo "[StripComment] preprocessing complete. Use temp dir: $tmp\n";
            if ($dryRun) echo "[StripComment] dry-run enabled: no files written in preprocess stage.\n";
        }

        // 返回临时目录（调用者负责检查并在适当时删除）
        return $tmp;
    }

    // -------------------- 内部工具函数 --------------------

    private static function pathExcluded(string $relPath, array $patterns): bool
    {
        if (empty($patterns)) return false;
        foreach ($patterns as $p) {
            if ($p === '') continue;
            if (stripos($relPath, $p) !== false) return true;
        }
        return false;
    }

    private static function stripPhpComments(string $code): string
    {
        $tokens = token_get_all($code);
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];
                $text = $token[1];
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $text;
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    private static function copyRecursive(string $src, string $dst, array $excludes = [], bool $verbose = false): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $srcBase = rtrim($src, DIRECTORY_SEPARATOR);

        foreach ($it as $item) {
            $relPath = substr($item->getPathname(), strlen($srcBase) + 1);
            if (self::pathExcluded($relPath, $excludes)) {
                if ($verbose) echo "[StripComment] copy skipped (excluded): $relPath\n";
                continue;
            }
            $target = $dst . DIRECTORY_SEPARATOR . $relPath;
            if ($item->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0777, true);
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) @mkdir($dir, 0777, true);
                copy($item->getPathname(), $target);
                @chmod($target, fileperms($item->getPathname()) & 0777);
                if ($verbose) echo "[StripComment] copied: $relPath\n";
            }
        }
    }

    private static function preprocessStripComments(string $root, array $excludes = [], bool $backup = false, bool $dryRun = false, bool $verbose = false): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
        $rootTrim = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            if (strtolower($file->getExtension()) !== 'php') continue;

            $rel = substr($file->getPathname(), strlen($rootTrim));
            if (self::pathExcluded($rel, $excludes)) {
                if ($verbose) echo "[StripComment] preprocess skipped (excluded): $rel\n";
                continue;
            }

            $path = $file->getPathname();
            $orig = file_get_contents($path);
            if ($orig === false) {
                if ($verbose) echo "[StripComment] cannot read: $rel\n";
                continue;
            }

            $stripped = self::stripPhpComments($orig);

            if ($stripped === $orig) {
                if ($verbose) echo "[StripComment] no changes: $rel\n";
                continue;
            }

            if ($backup && !$dryRun) {
                $bak = $path . '.bak';
                if (!file_exists($bak)) {
                    file_put_contents($bak, $orig);
                    @chmod($bak, fileperms($path) & 0777);
                    if ($verbose) echo "[StripComment] backup created: $rel.bak\n";
                } else {
                    if ($verbose) echo "[StripComment] backup exists: $rel.bak\n";
                }
            }

            if ($dryRun) {
                if ($verbose) echo "[StripComment] dry-run: would strip $rel\n";
                continue;
            }

            if (false === file_put_contents($path, $stripped)) {
                if ($verbose) echo "[StripComment] write failed: $rel\n";
            } else {
                @chmod($path, fileperms($path) & 0777);
                if ($verbose) echo "[StripComment] stripped: $rel\n";
            }
        }
    }
}
