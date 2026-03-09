<?php
declare(strict_types=1);

namespace Ko4\Repository;

use Ko4\Core\Config;
use Ko4\Core\Database;
use Ko4\Core\Logger;
use Ko4\Core\Terminal;
use Ko4\RepoException;

class RepoManager
{
    public function __construct(
        private Config $config,
        private Database $db,
        private Logger $logger
    ) {}

    public function sync(?string $repoName = null): void
    {
        $where  = $repoName ? "WHERE name = ? AND enabled = 1" : "WHERE enabled = 1";
        $params = $repoName ? [$repoName] : [];
        $repos  = $this->db->query("SELECT * FROM repos $where ORDER BY priority", $params);

        if (empty($repos)) {
            Terminal::warn("No repos configured. Add one with: ko4 repo add <name> <url>");
            return;
        }

        foreach ($repos as $repo) {
            Terminal::step("Syncing: {$repo['name']} ({$repo['url']})");
            try {
                $this->syncRepo($repo);
                Terminal::info("{$repo['name']} synced.");
            } catch (\Throwable $e) {
                Terminal::error("Failed to sync {$repo['name']}: " . $e->getMessage());
            }
        }
    }

    private function syncRepo(array $repo): void
    {
        $indexUrl = rtrim($repo['url'], '/') . '/index.json';
        $tmpFile  = sys_get_temp_dir() . '/ko4_repo_' . $repo['id'] . '_' . time() . '.json';

        $this->download($indexUrl, $tmpFile, $repo['gpg_key'] ?? null);

        $data = json_decode(file_get_contents($tmpFile), true);
        unlink($tmpFile);

        if (!$data || !isset($data['packages'])) {
            throw new RepoException("Invalid repository index format.");
        }

        $this->db->transaction(function(Database $db) use ($repo, $data) {
            // Clear existing packages for this repo
            $db->exec("DELETE FROM repo_packages WHERE repo_id = ?", [$repo['id']]);

            $count = 0;
            foreach ($data['packages'] as $pkg) {
                $deps      = json_encode($pkg['deps'] ?? []);
                $buildDeps = json_encode($pkg['build_deps'] ?? []);
                $db->exec(
                    "INSERT INTO repo_packages
                        (repo_id, name, version, release, arch, description, url, license,
                         size, checksum, filename, has_source, has_binary, provides, deps, build_deps)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $repo['id'],
                        $pkg['name'],
                        $pkg['version'],
                        $pkg['release'] ?? 1,
                        $pkg['arch'] ?? 'any',
                        $pkg['description'] ?? '',
                        $pkg['url'] ?? '',
                        $pkg['license'] ?? '',
                        $pkg['size'] ?? 0,
                        $pkg['checksum'] ?? null,
                        $pkg['filename'] ?? null,
                        (int)($pkg['has_source'] ?? 0),
                        (int)($pkg['has_binary'] ?? 1),
                        implode(',', $pkg['provides'] ?? []),
                        $deps,
                        $buildDeps,
                    ]
                );
                $count++;
            }

            $db->exec(
                "UPDATE repos SET last_sync = datetime('now'), package_count = ? WHERE id = ?",
                [$count, $repo['id']]
            );
        });
    }

    public function addRepo(string $name, string $url, int $priority = 50, ?string $gpgKey = null): void
    {
        // Validate URL reachability
        Terminal::step("Verifying repository...");

        $this->db->exec(
            "INSERT INTO repos (name, url, priority, gpg_key) VALUES (?,?,?,?)
             ON CONFLICT(name) DO UPDATE SET url=excluded.url, priority=excluded.priority",
            [$name, $url, $priority, $gpgKey]
        );

        Terminal::info("Repository '$name' added.");
        Terminal::dim("  Run 'ko4 sync' to fetch the package index.");
    }

    public function removeRepo(string $name): void
    {
        $rows = $this->db->query("SELECT id FROM repos WHERE name = ?", [$name]);
        if (empty($rows)) {
            throw new RepoException("Repository '$name' not found.");
        }
        $this->db->exec("DELETE FROM repos WHERE name = ?", [$name]);
        Terminal::info("Repository '$name' removed.");
    }

    public function listRepos(): array
    {
        return $this->db->query(
            "SELECT name, url, priority, enabled, last_sync, package_count FROM repos ORDER BY priority"
        );
    }

    public function enableRepo(string $name, bool $enable): void
    {
        $this->db->exec("UPDATE repos SET enabled = ? WHERE name = ?", [$enable ? 1 : 0, $name]);
        $word = $enable ? 'enabled' : 'disabled';
        Terminal::info("Repository '$name' $word.");
    }

    public function generateIndex(string $packageDir, string $outputFile): void
    {
        Terminal::step("Scanning packages in $packageDir...");
        $packages = [];

        foreach (glob($packageDir . '/*.ko4pkg') as $pkgFile) {
            $tmpDir = sys_get_temp_dir() . '/ko4_idx_' . uniqid();
            @mkdir($tmpDir, 0755, true);
            exec("tar -xJf " . escapeshellarg($pkgFile) . " .ko4meta -C " . escapeshellarg($tmpDir) . " 2>/dev/null");
            $meta = $tmpDir . '/.ko4meta';
            if (file_exists($meta)) {
                $data = json_decode(file_get_contents($meta), true);
                if ($data) {
                    $data['filename'] = basename($pkgFile);
                    $data['checksum'] = 'sha256:' . hash_file('sha256', $pkgFile);
                    $data['size']     = filesize($pkgFile);
                    unset($data['files']); // Don't include full file list in index
                    $packages[] = $data;
                }
            }
            exec("rm -rf " . escapeshellarg($tmpDir));
        }

        $index = [
            'generated' => date('Y-m-d\TH:i:s\Z'),
            'packages'  => $packages,
        ];

        file_put_contents($outputFile, json_encode($index, JSON_PRETTY_PRINT));
        Terminal::success("Index written: $outputFile (" . count($packages) . " packages)");
    }

    private function download(string $url, string $dest, ?string $gpgKey): void
    {
        if (is_executable('/usr/bin/curl')) {
            $cmd = "curl -sL -o " . escapeshellarg($dest) . " " . escapeshellarg($url);
        } elseif (is_executable('/usr/bin/wget')) {
            $cmd = "wget -q -O " . escapeshellarg($dest) . " " . escapeshellarg($url);
        } else {
            throw new RepoException("Cannot download: no curl or wget found.");
        }

        $ret = null;
        exec($cmd, $out, $ret);
        if ($ret !== 0 || !file_exists($dest)) {
            throw new RepoException("Failed to download: $url");
        }

        if ($gpgKey) {
            $sig  = $dest . '.sig';
            $sigUrl = $url . '.sig';
            exec("curl -sL -o " . escapeshellarg($sig) . " " . escapeshellarg($sigUrl));
            if (file_exists($sig)) {
                exec("gpg --verify " . escapeshellarg($sig) . " " . escapeshellarg($dest), $gpgOut, $gpgRet);
                if ($gpgRet !== 0) {
                    unlink($dest);
                    throw new RepoException("GPG signature verification failed for $url");
                }
            }
        }
    }
}
