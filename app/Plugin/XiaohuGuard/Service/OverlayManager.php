<?php
declare(strict_types=1);

namespace App\Plugin\XiaohuGuard\Service;

use App\Model\Config;
use App\Util\Plugin;
use RuntimeException;

final class OverlayManager
{
    private const PLUGIN = 'XiaohuGuard';
    private const THEME = 'Xiaohu';
    private const MANIFEST = 'manifest.json';

    /**
     * Only project customizations needed by the Xiaohu three-platform shop are
     * protected. Runtime configuration and secrets are intentionally excluded.
     */
    private const TARGETS = [
        'app/Controller/Admin/Api/Plugin.php',
        'app/Controller/Admin/Api/Config.php',
        'app/Controller/Admin/Config.php',
        'config/redis.php',
        'kernel/Cache/Cache.php',
        'kernel/Util/RedisClient.php',
        'app/Controller/User/Api/Category.php',
        'app/Plugin/ManualShopPage/Config/Info.php',
        'app/Plugin/ManualShopPage/Config/Submit.php',
        'app/Plugin/ManualShopPage/Service/Navigation.php',
        'app/Plugin/ManualShopPage/Wiki/README.md',
        'kernel/Kernel.php',
        'app/Controller/Admin/Api/Category.php',
        'app/Controller/Admin/Api/Commodity.php',
        'app/Controller/Base/View/User.php',
        'app/Controller/User/Index.php',
        'app/Controller/User/Api/Index.php',
        'app/Model/Category.php',
        'app/Service/Bind/Dict.php',
        'app/Service/Bind/Shop.php',
        'app/View/User/Helper.php',
        'assets/admin/controller/trade/category.js',
        'assets/admin/controller/trade/commodity.js',
        'assets/admin/controller/config/plugin.js',
        'app/View/Admin/Config/Plugin.html',
        'assets/user/controller/index/index.js',
        'assets/user/js/trade.js',
        'app/View/User/Theme/Xiaohu',
        'app/View/User/Theme/Cartoon/Index/Header.html',
    ];

    private const EXCLUDED = [
        // This file contains settings saved by the administrator and must never
        // be reverted to an old snapshot on the next request.
        'app/View/User/Theme/Xiaohu/Setting.php',
    ];

    public function hasSnapshot(): bool
    {
        return is_file($this->manifestPath());
    }

    public function capture(bool $force = false): array
    {
        if ($this->hasSnapshot() && !$force) {
            return $this->readManifest();
        }

        $version = $this->appVersion();
        $root = $this->snapshotRoot($version);
        if ($force && is_dir($root)) {
            $this->removeDirectory($root);
        }

        $files = [];
        foreach ($this->expandTargets() as $relativePath) {
            $source = $this->absolute($relativePath);
            $destination = $root . '/' . $relativePath;
            $this->copyAtomic($source, $destination);
            $files[$relativePath] = [
                'sha256' => hash_file('sha256', $source),
                'size' => filesize($source),
            ];
        }

        ksort($files);
        $manifest = [
            'format' => 1,
            'theme' => self::THEME,
            'version' => $version,
            'captured_at' => date(DATE_ATOM),
            'files' => $files,
        ];
        $this->writeJsonAtomic($root . '/' . self::MANIFEST, $manifest);

        return $manifest;
    }

    public function restoreWhenXiaohuIsActive(): array
    {
        if ((string)Config::get('user_theme') !== self::THEME) {
            return [
                'status' => 'inactive_theme',
                'restored' => 0,
                'unchanged' => 0,
            ];
        }

        return $this->restore();
    }

    public function restore(): array
    {
        if (!$this->hasSnapshot()) {
            return [
                'status' => 'missing_snapshot',
                'restored' => 0,
                'unchanged' => 0,
                'warning' => '尚未生成定制快照',
            ];
        }

        $manifest = $this->readManifest();
        $snapshotVersion = (string)($manifest['version'] ?? '');
        $currentVersion = $this->appVersion();
        $config = Plugin::getConfig(self::PLUGIN, false);
        $strict = (int)($config['strict_version'] ?? 1) === 1;

        if ($strict && $snapshotVersion !== $currentVersion) {
            return [
                'status' => 'version_mismatch',
                'restored' => 0,
                'unchanged' => 0,
                'warning' => "快照版本{$snapshotVersion}与当前版本{$currentVersion}不同，已停止覆盖",
            ];
        }

        $root = $this->snapshotRoot($snapshotVersion);
        $restored = 0;
        $unchanged = 0;
        foreach ((array)($manifest['files'] ?? []) as $relativePath => $metadata) {
            $relativePath = $this->normalizeRelativePath((string)$relativePath);
            if ($this->isExcluded($relativePath)) {
                continue;
            }

            $snapshot = $root . '/' . $relativePath;
            $destination = $this->absolute($relativePath);
            $expectedHash = (string)($metadata['sha256'] ?? '');
            if (!is_file($snapshot) || $expectedHash === '' || hash_file('sha256', $snapshot) !== $expectedHash) {
                throw new RuntimeException("小虎定制快照损坏：{$relativePath}");
            }

            if (is_file($destination) && hash_file('sha256', $destination) === $expectedHash) {
                $unchanged++;
                continue;
            }

            $this->copyAtomic($snapshot, $destination);
            $restored++;
        }

        if ($restored > 0) {
            $this->clearCaches();
        }

        return [
            'status' => 'ok',
            'restored' => $restored,
            'unchanged' => $unchanged,
        ];
    }

    private function expandTargets(): array
    {
        $files = [];
        foreach (self::TARGETS as $target) {
            $target = $this->normalizeRelativePath($target);
            $absolute = $this->absolute($target);
            if (is_file($absolute)) {
                if (!$this->isExcluded($target)) {
                    $files[] = $target;
                }
                continue;
            }

            if (!is_dir($absolute)) {
                throw new RuntimeException("要保护的定制文件不存在：{$target}");
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }
                $relativePath = ltrim(str_replace(BASE_PATH, '', $item->getPathname()), '/');
                $relativePath = $this->normalizeRelativePath($relativePath);
                if (!$this->isExcluded($relativePath)) {
                    $files[] = $relativePath;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    private function readManifest(): array
    {
        $path = $this->manifestPath();
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('小虎定制快照清单无效');
        }
        return $manifest;
    }

    private function manifestPath(): string
    {
        $config = Plugin::getConfig(self::PLUGIN, false);
        $version = trim((string)($config['protected_version'] ?? $this->appVersion()));
        return $this->snapshotRoot($version) . '/' . self::MANIFEST;
    }

    private function snapshotRoot(string $version): string
    {
        if (!preg_match('/^[0-9A-Za-z._-]+$/', $version)) {
            throw new RuntimeException('非法快照版本号');
        }
        return BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/Snapshot/' . $version;
    }

    private function appVersion(): string
    {
        return (string)(config('app')['version'] ?? 'unknown');
    }

    private function absolute(string $relativePath): string
    {
        return rtrim(BASE_PATH, '/') . '/' . $this->normalizeRelativePath($relativePath);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '../') || $path === '..') {
            throw new RuntimeException('非法定制文件路径');
        }
        return $path;
    }

    private function isExcluded(string $relativePath): bool
    {
        return in_array($relativePath, self::EXCLUDED, true);
    }

    private function copyAtomic(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new RuntimeException("复制源文件不存在：{$source}");
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("无法创建目录：{$directory}");
        }

        $temporary = $destination . '.xiaohu-' . bin2hex(random_bytes(4));
        if (!copy($source, $temporary)) {
            throw new RuntimeException("无法写入定制文件：{$destination}");
        }
        @chmod($temporary, fileperms($source) & 0777);
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException("无法替换定制文件：{$destination}");
        }
    }

    private function writeJsonAtomic(string $path, array $value): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("无法创建快照目录：{$directory}");
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('无法写入小虎定制快照清单');
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('无法保存小虎定制快照清单');
        }
    }

    private function clearCaches(): void
    {
        $paths = [
            BASE_PATH . '/runtime/cache',
            BASE_PATH . '/runtime/view',
        ];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path, false);
            }
        }
    }

    private function removeDirectory(string $path, bool $removeRoot = true): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        if ($removeRoot) {
            @rmdir($path);
        }
    }
}
