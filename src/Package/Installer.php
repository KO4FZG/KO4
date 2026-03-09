<?php
declare(strict_types=1);

namespace Ko4\Package;

use Ko4\Core\Config;
use Ko4\Core\Logger;
use Ko4\Core\Terminal;
use Ko4\IntegrityException;

class Installer
{
    private string $root;

    public function __construct(
        private Config $config,
        private PackageRegistry $registry,
        private Logger $logger
    ) {
        $this->root = rtrim($config->get('install_root', '/'), '/');
    }

    /**
     * Install a Package object from a repo (downloads binary .ko4pkg).
     */
    public function install(Package $pkg, string $reason = 'explicit'): void
    {
        $pkgFile = $this->downloadPackage($pkg);
        $this->installFile($pkgFile, $reason);
    }

    /**
     * Install from a local .ko4pkg archive file.
     */
    public function installFile(string $pkgFile, string $reason = 'explicit'): void
    {
        if (!file_exists($pkgFile)) {
            throw new \RuntimeException("Package file not found: $pkgFile");
        }

        // Extract to temp dir
        $tmpDir = sys_get_temp_dir() . '/ko4_install_' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $ret = null;
            exec("tar -xJf " . escapeshellarg($pkgFile) . " -C " . escapeshellarg($tmpDir) . " 2>&1", $out, $ret);
            if ($ret !== 0) {
                throw new \RuntimeException("Failed to extract package: " . implode("\n", $out));
            }

            // Read metadata
            $metaFile = $tmpDir . '/.ko4meta';
            if (!file_exists($metaFile)) {
                throw new \RuntimeException("Package missing .ko4meta — may be corrupt.");
            }
            $meta = json_decode(file_get_contents($metaFile), true);

            // Verify integrity
            $this->verifyIntegrity($tmpDir, $meta['files'] ?? []);

            // Run pre-install hook
            $this->runHook($tmpDir, 'pre-install', $meta);

            // Handle conflicts
            $this->handleConflicts($meta);

            // Actually install files
            $installedFiles = $this->deployFiles($tmpDir, $meta['files'] ?? []);

            // Register in DB
            $pkg               = Package::fromArray($meta);
            $pkg->installReason = $reason;
            $this->registry->register($pkg, $installedFiles);

            // Cache the package file
            $cacheDir = KO4_CACHE . '/packages';
            @mkdir($cacheDir, 0755, true);
            $cacheDest = $cacheDir . '/' . basename($pkgFile);
            if ($pkgFile !== $cacheDest) {
                copy($pkgFile, $cacheDest);
            }

            // Run post-install hook
            $this->runHook($tmpDir, 'post-install', $meta);

            $this->logger->info("Installed: {$meta['name']} {$meta['version']}");
        } finally {
            $this->rmdirRecursive($tmpDir);
        }
    }

    /**
     * Remove a package from the system.
     */
    public function remove(string $name, bool $keepFiles = false): void
    {
        $pkg = $this->registry->find($name);
        if (!$pkg) {
            throw new \RuntimeException("Package $name is not installed.");
        }

        $files = $this->registry->getFiles($name);

        // Run pre-remove hook
        $this->runNamedHook($name, 'pre-remove');

        if (!$keepFiles) {
            // Remove files in reverse order (deepest first)
            $paths = array_column($files, 'path');
            rsort($paths);

            foreach ($paths as $rel) {
                $full = $this->root . $rel;
                if (!file_exists($full) && !is_link($full)) continue;

                // Check if another package owns this file (shared)
                $owner = $this->registry->findOwner($rel);
                if ($owner && $owner !== $name) continue;

                if (is_dir($full) && !is_link($full)) {
                    // Only remove if empty
                    $contents = scandir($full);
                    if (count($contents) <= 2) @rmdir($full);
                } else {
                    @unlink($full);
                }
            }

            // Run ldconfig if we removed libraries
            $hasLibs = !empty(array_filter($paths, fn($p) => str_contains($p, '/lib/')));
            if ($hasLibs) exec('ldconfig 2>/dev/null || true');
        }

        $this->registry->unregister($name);
        $this->runNamedHook($name, 'post-remove');
        $this->logger->info("Removed: $name {$pkg->version}");
    }

    private function downloadPackage(Package $pkg): string
    {
        $cacheFile = KO4_CACHE . "/packages/{$pkg->name}-{$pkg->version}-{$pkg->release}.ko4pkg";

        if (file_exists($cacheFile)) {
            Terminal::dim("  Using cached: " . basename($cacheFile));
            if ($pkg->checksum) $this->verifyFileChecksum($cacheFile, $pkg->checksum);
            return $cacheFile;
        }

        // Find repo URL
        $row = $this->db ?? null; // Resolved at repo level; for now construct URL
        // Repo download logic would go here based on repo_packages.filename
        throw new \RuntimeException("Download not implemented without active repo. Build from source: ko4 build {$pkg->name}");
    }

    private function verifyIntegrity(string $dir, array $files): void
    {
        foreach ($files as $file) {
            if ($file['type'] !== 'file') continue;
            $path = $dir . '/' . ltrim($file['path'], '/');
            if (!file_exists($path)) continue;
            if (!$file['checksum']) continue;

            $actual = hash_file('sha256', $path);
            if ($actual !== $file['checksum']) {
                throw new IntegrityException(
                    "Integrity check failed for {$file['path']}: " .
                    "expected {$file['checksum']}, got $actual"
                );
            }
        }
    }

    private function handleConflicts(array $meta): void
    {
        $conflicts = $meta['conflicts'] ?? [];
        foreach ($conflicts as $conflict) {
            if ($this->registry->isInstalled($conflict)) {
                throw new \RuntimeException(
                    "Package '{$meta['name']}' conflicts with installed package '$conflict'."
                );
            }
        }
    }

    private function deployFiles(string $srcDir, array $files): array
    {
        $installed = [];
        $total     = count($files);
        $i         = 0;

        foreach ($files as $file) {
            $i++;
            $rel  = $file['path'];
            $src  = $srcDir . '/' . ltrim($rel, '/');
            $dest = $this->root . $rel;

            // Skip meta files
            if (in_array(basename($rel), ['.ko4meta', '.KO4BUILD'])) continue;
            if (str_starts_with($rel, '/.')) continue;

            Terminal::progress("Installing files", $i, $total);

            @mkdir(dirname($dest), 0755, true);

            if ($file['type'] === 'dir') {
                @mkdir($dest, octdec($file['mode'] ?? '755'), true);
            } elseif ($file['type'] === 'symlink') {
                // Handle symlinks (stored as targets in meta)
                // Skip — real symlinks extracted by tar already
            } elseif (file_exists($src)) {
                // Backup existing config files
                if ($this->isConfigFile($rel) && file_exists($dest)) {
                    rename($dest, $dest . '.luxorig');
                }
                copy($src, $dest);
                if (isset($file['mode'])) {
                    chmod($dest, octdec($file['mode']));
                }
            }

            $installed[] = [
                'path'     => $rel,
                'checksum' => $file['checksum'] ?? null,
                'type'     => $file['type'],
            ];
        }

        // Update shared libraries
        exec('ldconfig 2>/dev/null || true');

        return $installed;
    }

    private function isConfigFile(string $path): bool
    {
        return str_starts_with($path, '/etc/');
    }

    private function runHook(string $pkgDir, string $hook, array $meta): void
    {
        $script = $pkgDir . "/.hooks/$hook";
        if (!file_exists($script)) return;
        chmod($script, 0755);
        $env = "name={$meta['name']} version={$meta['version']}";
        exec("$env bash " . escapeshellarg($script) . " 2>&1");
    }

    private function runNamedHook(string $name, string $hook): void
    {
        $script = KO4_HOOKS . "/$name/$hook";
        if (!file_exists($script)) return;
        chmod($script, 0755);
        exec("bash " . escapeshellarg($script) . " 2>&1");
    }

    private function verifyFileChecksum(string $file, string $checksum): void
    {
        if (!str_contains($checksum, ':')) {
            $checksum = 'sha256:' . $checksum;
        }
        [$algo, $expected] = explode(':', $checksum, 2);
        $actual = hash_file($algo, $file);
        if ($actual !== $expected) {
            throw new IntegrityException("Checksum mismatch for " . basename($file));
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
        }
        @rmdir($dir);
    }
}
