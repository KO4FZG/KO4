<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

// ── Search ────────────────────────────────────────────────────────────────────

class SearchCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 search <query>");
            return 1;
        }

        $query   = implode(' ', $args);
        $results = $this->registry->search($query);

        if (empty($results)) {
            Terminal::warn("No packages found matching '$query'.");
            return 0;
        }

        Terminal::header("Search results for '$query'");
        $rows = [];
        foreach ($results as $r) {
            $status = $r['installed'] ? "\033[32m[installed]\033[0m" : '';
            $src    = $r['has_source'] ? 'src' : '';
            $bin    = $r['has_binary'] ? 'bin' : '';
            $avail  = implode('/', array_filter([$bin, $src]));
            $rows[] = [
                $r['name'],
                $r['version'],
                $r['repo_name'] ?? '?',
                $avail,
                $status,
                substr($r['description'] ?? '', 0, 50),
            ];
        }
        Terminal::table(['Name', 'Version', 'Repo', 'Type', 'Status', 'Description'], $rows);
        echo "  " . count($results) . " package(s) found.\n\n";
        return 0;
    }
}

// ── Info ──────────────────────────────────────────────────────────────────────

class InfoCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 info <package>");
            return 1;
        }

        $name = $args[0];
        $pkg  = $this->registry->find($name) ?? $this->registry->findInRepo($name);

        if (!$pkg) {
            Terminal::error("Package '$name' not found.");
            return 1;
        }

        Terminal::header("Package: $name");

        $installed = $this->registry->isInstalled($name);
        $pinned    = $this->registry->isPinned($name);

        $fields = [
            ['Name',        $pkg->name],
            ['Version',     $pkg->fullVersion()],
            ['Arch',        $pkg->arch],
            ['Description', $pkg->description],
            ['URL',         $pkg->url],
            ['License',     $pkg->license],
            ['Groups',      implode(', ', $pkg->groups) ?: '(none)'],
            ['Provides',    implode(', ', $pkg->provides) ?: '(none)'],
            ['Conflicts',   implode(', ', $pkg->conflicts) ?: '(none)'],
            ['Installed',   $installed ? "\033[32myes\033[0m" : "\033[33mno\033[0m"],
            ['Pinned',      $pinned    ? "\033[33myes\033[0m" : 'no'],
            ['Size',        Terminal::formatSize($pkg->size)],
        ];

        if ($installed) {
            $fields[] = ['Install Date', $pkg->installDate ?? '?'];
            $fields[] = ['Install Reason', $pkg->installReason];
        }

        if ($pkg->buildDate) {
            $fields[] = ['Build Date', $pkg->buildDate];
        }

        $labelWidth = 15;
        foreach ($fields as [$label, $value]) {
            $lpad = str_pad($label . ':', $labelWidth);
            echo "  \033[1m$lpad\033[0m $value\n";
        }

        // Dependencies
        echo "\n";
        if (!empty($pkg->deps)) {
            $required = array_keys(array_filter($pkg->deps, fn($t) => $t === 'required'));
            $optional = array_keys(array_filter($pkg->deps, fn($t) => $t === 'optional'));
            $makedeps = array_keys(array_filter($pkg->deps, fn($t) => $t === 'makedep'));

            if ($required) {
                echo "  \033[1mDependencies:\033[0m   " . implode(', ', $required) . "\n";
            }
            if ($optional) {
                echo "  \033[1mOptional Deps:\033[0m  " . implode(', ', $optional) . "\n";
            }
            if ($makedeps) {
                echo "  \033[1mBuild Deps:\033[0m     " . implode(', ', $makedeps) . "\n";
            }
        }

        echo "\n";
        return 0;
    }
}

// ── List ──────────────────────────────────────────────────────────────────────

class ListCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $filter  = $args[0] ?? '';
        $verbose = isset($flags['v']) || isset($flags['verbose']);
        $explicit = isset($flags['explicit']) || isset($flags['e']);

        $pkgs = $this->registry->getInstalledList();

        if ($explicit) {
            $pkgs = array_filter($pkgs, fn($p) => $p['install_reason'] === 'explicit');
        }

        if ($filter) {
            $pkgs = array_filter($pkgs, fn($p) => str_contains($p['name'], $filter));
        }

        if (empty($pkgs)) {
            Terminal::dim("No packages installed.");
            return 0;
        }

        Terminal::header("Installed Packages (" . count($pkgs) . ")");

        if ($verbose) {
            $rows = array_map(fn($p) => [
                $p['name'],
                $p['version'] . '-' . ($p['release'] ?? 1),
                $p['arch'] ?? 'any',
                $p['install_reason'],
                Terminal::formatSize((int)$p['size']),
                substr($p['description'] ?? '', 0, 40),
            ], $pkgs);
            Terminal::table(['Name', 'Version', 'Arch', 'Reason', 'Size', 'Description'], $rows);
        } else {
            foreach ($pkgs as $p) {
                $reason = $p['install_reason'] === 'dependency' ? ' \033[2m[dep]\033[0m' : '';
                echo "  {$p['name']} \033[36m{$p['version']}\033[0m$reason\n";
            }
        }

        echo "\n";
        return 0;
    }
}

// ── Files ─────────────────────────────────────────────────────────────────────

class FilesCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 files <package>");
            return 1;
        }

        $name  = $args[0];
        $files = $this->registry->getFiles($name);

        if (empty($files)) {
            if (!$this->registry->isInstalled($name)) {
                Terminal::error("Package '$name' is not installed.");
                return 1;
            }
            Terminal::dim("No files recorded for $name.");
            return 0;
        }

        Terminal::header("Files owned by $name");
        foreach ($files as $f) {
            $icon = match($f['file_type']) {
                'dir'     => "\033[34m[d]\033[0m",
                'symlink' => "\033[33m[l]\033[0m",
                default   => "   ",
            };
            echo "  $icon {$f['path']}\n";
        }
        echo "\n  " . count($files) . " file(s)\n\n";
        return 0;
    }
}

// ── Owns ──────────────────────────────────────────────────────────────────────

class OwnsCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 owns <file>");
            return 1;
        }

        $file = realpath($args[0]) ?: $args[0];
        // Strip install root if present
        $root = $this->config->get('install_root', '/');
        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }
        if (!str_starts_with($file, '/')) $file = '/' . $file;

        $owner = $this->registry->findOwner($file);
        if (!$owner) {
            Terminal::warn("No package owns '$file'.");
            return 1;
        }

        Terminal::info("'$file' is owned by: \033[1m$owner\033[0m");
        return 0;
    }
}

// ── Deps ─────────────────────────────────────────────────────────────────────

class DepsCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 deps <package> | ko4 rdeps <package>");
            return 1;
        }

        $name = $args[0];

        if ($this->commandName === 'rdeps') {
            $rdeps = $this->registry->getReverseDeps($name);
            if (empty($rdeps)) {
                Terminal::info("No packages depend on '$name'.");
                return 0;
            }
            Terminal::header("Packages that depend on $name");
            foreach ($rdeps as $r) {
                echo "  → {$r['name']}\n";
            }
        } else {
            Terminal::header("Dependency tree for $name");
            $tree = $this->resolver->buildTree($name);
            echo $tree;
        }

        echo "\n";
        return 0;
    }
}

// ── Sync ──────────────────────────────────────────────────────────────────────

class SyncCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $repoName = $args[0] ?? null;
        $mgr = new RepoManager($this->config, $this->db, $this->logger);
        $mgr->sync($repoName);
        return 0;
    }
}

// ── Repo ──────────────────────────────────────────────────────────────────────

class RepoCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $sub = $args[0] ?? 'list';
        $mgr = new RepoManager($this->config, $this->db, $this->logger);

        switch ($sub) {
            case 'add':
                if (count($args) < 3) {
                    Terminal::error("Usage: ko4 repo add <name> <url> [priority]");
                    return 1;
                }
                $mgr->addRepo($args[1], $args[2], (int)($args[3] ?? 50), $flags['gpg-key'] ?? null);
                break;

            case 'remove':
            case 'rm':
                if (empty($args[1])) {
                    Terminal::error("Usage: ko4 repo remove <name>");
                    return 1;
                }
                $mgr->removeRepo($args[1]);
                break;

            case 'enable':
            case 'disable':
                if (empty($args[1])) {
                    Terminal::error("Usage: ko4 repo {enable|disable} <name>");
                    return 1;
                }
                $mgr->enableRepo($args[1], $sub === 'enable');
                break;

            case 'index':
                if (empty($args[1])) {
                    Terminal::error("Usage: ko4 repo index <package-dir> [output.json]");
                    return 1;
                }
                $out = $args[2] ?? $args[1] . '/index.json';
                $mgr->generateIndex($args[1], $out);
                break;

            case 'list':
            default:
                $repos = $mgr->listRepos();
                if (empty($repos)) {
                    Terminal::warn("No repositories configured.");
                    Terminal::dim("  Add one with: ko4 repo add <name> <url>");
                    return 0;
                }
                Terminal::header("Configured Repositories");
                $rows = array_map(fn($r) => [
                    $r['name'],
                    $r['enabled'] ? "\033[32m✔\033[0m" : "\033[31m✖\033[0m",
                    $r['priority'],
                    $r['package_count'] ?? 0,
                    $r['last_sync'] ?? 'never',
                    substr($r['url'], 0, 50),
                ], $repos);
                Terminal::table(['Name', 'En', 'Pri', 'Pkgs', 'Last Sync', 'URL'], $rows);
        }

        return 0;
    }
}

// ── Log ───────────────────────────────────────────────────────────────────────

class LogCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $limit  = (int)($flags['n'] ?? $flags['limit'] ?? 20);
        $filter = $args[0] ?? null;

        $rows = $this->registry->getLog($filter ? 200 : $limit);

        if ($filter) {
            $rows = array_filter($rows, fn($r) => str_contains($r['package'], $filter));
            $rows = array_slice($rows, 0, $limit);
        }

        if (empty($rows)) {
            Terminal::dim("No transaction history.");
            return 0;
        }

        Terminal::header("Transaction Log");
        $tableRows = array_map(fn($r) => [
            $r['timestamp'],
            strtoupper($r['action']),
            $r['package'],
            $r['old_version'] ? ($r['old_version'] . ' → ' . $r['new_version']) : ($r['new_version'] ?? ''),
            $r['user'] ?? '?',
            $r['success'] ? "\033[32m✔\033[0m" : "\033[31m✖\033[0m",
        ], $rows);

        Terminal::table(['Timestamp', 'Action', 'Package', 'Version', 'User', 'OK'], $tableRows);
        return 0;
    }
}

// ── Clean ─────────────────────────────────────────────────────────────────────

class CleanCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $all     = isset($flags['all']) || isset($flags['a']);
        $cacheDir = KO4_CACHE . '/packages';

        if (!is_dir($cacheDir)) {
            Terminal::info("Cache is already empty.");
            return 0;
        }

        $files = glob($cacheDir . '/*.ko4pkg') ?: [];
        if (empty($files)) {
            Terminal::info("Nothing to clean.");
            return 0;
        }

        // Keep only newest version per package if not --all
        $toDelete = [];
        if ($all) {
            $toDelete = $files;
        } else {
            $byName = [];
            foreach ($files as $f) {
                preg_match('/([^\/]+)-(\d.+?)\.ko4pkg$/', basename($f), $m);
                $byName[$m[1] ?? $f][] = $f;
            }
            foreach ($byName as $name => $versions) {
                sort($versions);
                array_pop($versions); // keep newest
                $toDelete = array_merge($toDelete, $versions);
            }
        }

        if (empty($toDelete)) {
            Terminal::info("Nothing to clean (only newest versions kept).");
            return 0;
        }

        $size = array_sum(array_map('filesize', $toDelete));
        echo "\n  Will delete " . count($toDelete) . " file(s), freeing " . Terminal::formatSize($size) . "\n\n";

        if (!$this->confirmAction("Proceed?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        foreach ($toDelete as $f) {
            unlink($f);
        }
        Terminal::success("Freed " . Terminal::formatSize($size) . ".");
        return 0;
    }
}

// ── Autoremove ────────────────────────────────────────────────────────────────

class AutoremoveCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $this->requireRoot();
        $orphans = $this->registry->getOrphanedPackages();

        if (empty($orphans)) {
            Terminal::success("No orphaned packages.");
            return 0;
        }

        Terminal::header("Orphaned Packages (installed as deps, no longer needed)");
        foreach ($orphans as $o) {
            echo "  \033[33m-\033[0m {$o['name']} {$o['version']}\n";
        }
        echo "\n";

        if (!$this->confirmAction("Remove these packages?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        $installer = new \Ko4\Package\Installer($this->config, $this->registry, $this->logger);
        foreach ($orphans as $o) {
            try {
                $installer->remove($o['name']);
                Terminal::info("{$o['name']} removed.");
            } catch (\Throwable $e) {
                Terminal::error("Failed to remove {$o['name']}: " . $e->getMessage());
            }
        }
        Terminal::success("Done.");
        return 0;
    }
}

// ── Pin/Unpin ─────────────────────────────────────────────────────────────────

class PinCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            if ($this->commandName === 'pin') {
                $pinned = $this->registry->getPinned();
                if (empty($pinned)) {
                    Terminal::dim("No pinned packages.");
                    return 0;
                }
                Terminal::header("Pinned Packages");
                $rows = array_map(fn($p) => [
                    $p['name'],
                    $p['version'] ?? '*',
                    $p['reason'] ?? '',
                    $p['pinned_at'],
                ], $pinned);
                Terminal::table(['Package', 'Version', 'Reason', 'Pinned At'], $rows);
                return 0;
            }
            Terminal::error("Usage: ko4 {pin|unpin} <package>");
            return 1;
        }

        $name    = $args[0];
        $version = $flags['version'] ?? null;
        $reason  = $flags['reason'] ?? null;

        if ($this->commandName === 'unpin') {
            $this->registry->unpin($name);
            Terminal::info("$name unpinned.");
        } else {
            $this->registry->pin($name, $version, $reason);
            Terminal::info("$name pinned" . ($version ? " at $version" : '') . ".");
        }
        return 0;
    }
}

// ── Verify ────────────────────────────────────────────────────────────────────

class VerifyCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $packages = empty($args) ? array_column($this->registry->getInstalledList(), 'name') : $args;
        $errors   = 0;

        Terminal::header("Verifying package integrity...");

        foreach ($packages as $name) {
            $files  = $this->registry->getFiles($name);
            $broken = [];

            foreach ($files as $f) {
                if ($f['file_type'] !== 'file') continue;
                $path = $this->config->get('install_root', '/') . $f['path'];
                if (!file_exists($path)) {
                    $broken[] = ['missing', $f['path']];
                } elseif ($f['checksum']) {
                    $actual = hash_file('sha256', $path);
                    if ($actual !== $f['checksum']) {
                        $broken[] = ['modified', $f['path']];
                    }
                }
            }

            if (empty($broken)) {
                echo "  \033[32m✔\033[0m $name\n";
            } else {
                echo "  \033[31m✖\033[0m $name\n";
                foreach ($broken as [$status, $path]) {
                    echo "      \033[31m[$status]\033[0m $path\n";
                }
                $errors++;
            }
        }

        echo "\n";
        if ($errors > 0) {
            Terminal::warn("$errors package(s) have integrity issues. Run 'ko4 reinstall <pkg>' to fix.");
            return 1;
        }
        Terminal::success("All packages OK.");
        return 0;
    }
}

// ── Audit ─────────────────────────────────────────────────────────────────────

class AuditCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        Terminal::header("Security Audit");

        $installed = $this->registry->getInstalledList();
        $issues    = 0;

        foreach ($installed as $pkg) {
            $avail = $this->registry->findInRepo($pkg['name']);
            if (!$avail) continue;

            if (\Ko4\Package\Package::compareVersions($avail->version, $pkg['version']) > 0) {
                echo "  \033[33m[UPDATE]\033[0m {$pkg['name']}: {$pkg['version']} → {$avail->version}\n";
                $issues++;
            }
        }

        if ($issues === 0) {
            Terminal::success("No outdated packages found.");
        } else {
            echo "\n";
            Terminal::warn("$issues package(s) have updates available. Run 'ko4 upgrade' to update.");
        }

        return 0;
    }
}

// ── Export/Import ─────────────────────────────────────────────────────────────

class ExportCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $out     = $args[0] ?? 'ko4-packages.json';
        $pkgs    = $this->registry->getInstalledList();
        $explicit = array_filter($pkgs, fn($p) => $p['install_reason'] === 'explicit');

        $data = [
            'exported'  => date('Y-m-d\TH:i:s\Z'),
            'lux_version' => KO4_VERSION,
            'packages'  => array_values(array_map(fn($p) => [
                'name'    => $p['name'],
                'version' => $p['version'],
            ], $explicit)),
        ];

        file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT));
        Terminal::success("Exported " . count($explicit) . " packages to $out");
        return 0;
    }
}

class ImportCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 import <packages.json>");
            return 1;
        }

        $this->requireRoot();

        $file = $args[0];
        if (!file_exists($file)) {
            Terminal::error("File not found: $file");
            return 1;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['packages'])) {
            Terminal::error("Invalid export file.");
            return 1;
        }

        $names = array_column($data['packages'], 'name');
        Terminal::info("Importing " . count($names) . " packages from $file");

        // Delegate to install command
        $install = new InstallCommand($this->config, $this->db, $this->logger);
        $install->setCommandName('install');
        return $install->execute($names, $flags);
    }
}

// ── Diff ──────────────────────────────────────────────────────────────────────

class DiffCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 diff <packages.json>");
            return 1;
        }

        $file = $args[0];
        if (!file_exists($file)) {
            Terminal::error("File not found: $file");
            return 1;
        }

        $data      = json_decode(file_get_contents($file), true);
        $saved     = array_column($data['packages'] ?? [], 'version', 'name');
        $installed = array_column($this->registry->getInstalledList(), 'version', 'name');

        $onlyInSaved     = array_diff_key($saved, $installed);
        $onlyInstalled   = array_diff_key($installed, $saved);
        $versionMismatch = [];

        foreach ($saved as $name => $ver) {
            if (isset($installed[$name]) && $installed[$name] !== $ver) {
                $versionMismatch[$name] = ['saved' => $ver, 'installed' => $installed[$name]];
            }
        }

        Terminal::header("Package Diff: $file vs current");

        if ($onlyInSaved) {
            echo "\033[33m  Missing from system:\033[0m\n";
            foreach ($onlyInSaved as $n => $v) echo "    - $n $v\n";
            echo "\n";
        }
        if ($onlyInstalled) {
            echo "\033[36m  Not in saved set:\033[0m\n";
            foreach ($onlyInstalled as $n => $v) echo "    + $n $v\n";
            echo "\n";
        }
        if ($versionMismatch) {
            echo "\033[35m  Version mismatches:\033[0m\n";
            foreach ($versionMismatch as $n => $v) {
                echo "    ~ $n saved={$v['saved']} installed={$v['installed']}\n";
            }
            echo "\n";
        }

        if (!$onlyInSaved && !$onlyInstalled && !$versionMismatch) {
            Terminal::success("System matches saved package set exactly.");
        }

        return 0;
    }
}

// ── Version ───────────────────────────────────────────────────────────────────

class VersionCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        Terminal::banner();
        echo "  Version:  " . KO4_VERSION . "\n";
        echo "  PHP:      " . PHP_VERSION . "\n";
        echo "  SQLite:   " . \SQLite3::version()['versionString'] . "\n";
        echo "  DB:       " . KO4_DB . "\n";
        echo "  Cache:    " . KO4_CACHE . "\n";
        echo "  Repos:    " . KO4_REPOS . "\n\n";
        return 0;
    }
}

// ── Create ────────────────────────────────────────────────────────────────────

class CreateCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 create <package-name>");
            return 1;
        }

        $name    = $args[0];
        $dir     = KO4_HOME . "/recipes/$name";
        $outFile = $dir . "/KO4BUILD";

        if (file_exists($outFile) && !isset($flags['force'])) {
            Terminal::error("KO4BUILD already exists at $outFile. Use --force to overwrite.");
            return 1;
        }

        @mkdir($dir, 0755, true);

        $version = Terminal::prompt("Version", "1.0.0");
        $desc    = Terminal::prompt("Description", "");
        $url     = Terminal::prompt("Homepage URL", "");
        $license = Terminal::prompt("License", "GPL-2.0");

        $template = $this->generateTemplate($name, $version, $desc, $url, $license);
        file_put_contents($outFile, $template);

        Terminal::success("Created: $outFile");
        Terminal::dim("  Edit the KO4BUILD file and run: ko4 build $name");
        return 0;
    }

    private function generateTemplate(
        string $name, string $version, string $desc, string $url, string $license
    ): string {
        return <<<KO4BUILD
[meta]
name        = {$name}
version     = {$version}
release     = 1
description = {$desc}
url         = {$url}
license     = {$license}
arch        = x86_64
# Comma-separated runtime dependencies
deps        = 
# Comma-separated build-time dependencies
makedeps    = gcc, make
# Optional: packages this provides (virtual packages)
# provides  = 
# Optional: packages this conflicts with
# conflicts = 

[sources]
# Format: <url> <algo>:<checksum>
# Variables: \${version} is replaced automatically
# https://example.com/downloads/{$name}-\${version}.tar.gz sha256:abc123...

[prepare]
#!/bin/bash
# Optional: run before build (e.g. patch, autoreconf)

[build]
#!/bin/bash
# Build the software
# Available variables:
#   \$SRCDIR  - extracted source directory
#   \$PKGDIR  - staging directory (install here, not /)
#   \$JOBS    - number of CPU cores
#   \$MAKEFLAGS - pre-set to -j\$JOBS

./configure \\
    --prefix=/usr \\
    --sysconfdir=/etc \\
    --localstatedir=/var

make \$MAKEFLAGS

[check]
#!/bin/bash
# Optional: run test suite (only if ko4.conf: run_tests = true)
# make check

[package]
#!/bin/bash
# Install files into \$PKGDIR (staging area, NOT the real system)
make DESTDIR="\$PKGDIR" install

# Install license
install -Dm644 COPYING "\$PKGDIR/usr/share/licenses/{$name}/LICENSE"

# Optional post-install script (runs on target system after install)
# install -Dm755 ../post-install.sh "\$PKGDIR/.hooks/post-install"

KO4BUILD;
    }
}

// ── Pack ──────────────────────────────────────────────────────────────────────

class PackCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $dir = $args[0] ?? '.';

        if (!file_exists("$dir/KO4BUILD")) {
            Terminal::error("No KO4BUILD found in $dir");
            return 1;
        }

        $this->requireRoot();

        $build = new BuildCommand($this->config, $this->db, $this->logger);
        $build->setCommandName('build');
        return $build->execute([$dir], array_merge($flags, ['no-install' => true]));
    }
}

// ── Help ──────────────────────────────────────────────────────────────────────

class HelpCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        Terminal::banner();

        $commands = [
            'Package Management' => [
                'install  <pkg...>'         => 'Install packages (binary by default)',
                'remove   <pkg...>'         => 'Remove packages',
                'upgrade  [pkg...]'         => 'Upgrade packages (all if none given)',
                'downgrade <pkg> <version>' => 'Downgrade to a specific version',
                'reinstall <pkg...>'        => 'Reinstall packages',
                'autoremove'                => 'Remove orphaned dependency packages',
            ],
            'Build from Source' => [
                'build   <pkg...>'          => 'Build package from source using KO4BUILD',
                'rebuild <pkg...>'          => 'Force rebuild (ignore cache)',
                'create  <name>'            => 'Create a new KO4BUILD recipe scaffold',
                'pack    [dir]'             => 'Build package archive from current dir',
            ],
            'Query' => [
                'search  <query>'           => 'Search for packages in repos',
                'info    <pkg>'             => 'Show detailed package information',
                'list    [filter]'          => 'List installed packages',
                'files   <pkg>'             => 'List files owned by a package',
                'owns    <file>'            => 'Find which package owns a file',
                'deps    <pkg>'             => 'Show dependency tree',
                'rdeps   <pkg>'             => 'Show reverse dependencies',
            ],
            'Repositories' => [
                'sync    [repo]'            => 'Sync package indexes from repos',
                'repo add <name> <url>'     => 'Add a repository',
                'repo remove <name>'        => 'Remove a repository',
                'repo list'                 => 'List configured repositories',
                'repo enable/disable <n>'   => 'Enable or disable a repository',
                'repo index <dir>'          => 'Generate a repo index from .ko4pkg files',
            ],
            'Maintenance' => [
                'verify  [pkg...]'          => 'Check installed file integrity',
                'audit'                     => 'Check for available updates',
                'clean   [--all]'           => 'Clean package cache',
                'log     [pkg]'             => 'View transaction history',
                'pin     <pkg>'             => 'Pin a package (prevent upgrades)',
                'unpin   <pkg>'             => 'Unpin a package',
                'export  [file]'            => 'Export installed package list to JSON',
                'import  <file>'            => 'Install packages from exported list',
                'diff    <file>'            => 'Compare system with exported list',
            ],
        ];

        foreach ($commands as $section => $cmds) {
            echo "\033[1;34m$section\033[0m\n";
            foreach ($cmds as $cmd => $desc) {
                $padded = str_pad("  ko4 $cmd", 36);
                echo "\033[36m$padded\033[0m $desc\n";
            }
            echo "\n";
        }

        echo "\033[1mGlobal Flags\033[0m\n";
        echo "  \033[36m-y, --yes\033[0m                       Skip confirmation prompts\n";
        echo "  \033[36m--source, -s\033[0m                    Force build from source\n";
        echo "  \033[36m--verbose, -v\033[0m                   Verbose output\n";
        echo "  \033[36m--no-install\033[0m                    Build only, don't install\n";
        echo "  \033[36m--asdep\033[0m                         Mark install reason as dependency\n";
        echo "  \033[36m--cascade\033[0m                       Remove dependents too\n";
        echo "\n";

        return 0;
    }
}
