<?php
declare(strict_types=1);

namespace Ko4\Package;

use Ko4\Core\Database;
use Ko4\PackageNotFoundException;

class PackageRegistry
{
    public function __construct(private Database $db) {}

    // ── Lookups ───────────────────────────────────────────────────────────────

    public function find(string $name): ?Package
    {
        $rows = $this->db->query(
            "SELECT * FROM packages WHERE name = ? AND installed = 1 ORDER BY version DESC LIMIT 1",
            [$name]
        );
        return $rows ? Package::fromRow($rows[0]) : null;
    }

    public function findInRepo(string $name): ?Package
    {
        $rows = $this->db->query(
            "SELECT rp.*, r.name as repo_name FROM repo_packages rp
             JOIN repos r ON r.id = rp.repo_id
             WHERE rp.name = ? AND r.enabled = 1
             ORDER BY r.priority ASC, rp.version DESC LIMIT 1",
            [$name]
        );
        if (!$rows) return null;
        $p = Package::fromRow($rows[0]);
        $p->deps      = json_decode($rows[0]['deps'] ?? '{}', true) ?: [];
        $p->hasSource = (bool)$rows[0]['has_source'];
        $p->hasBinary = (bool)$rows[0]['has_binary'];
        return $p;
    }

    public function findAll(string $filter = '', bool $installedOnly = false): array
    {
        $where  = $installedOnly ? 'WHERE installed = 1' : 'WHERE 1=1';
        $params = [];
        if ($filter !== '') {
            $where .= " AND (name LIKE ? OR description LIKE ?)";
            $params = ["%$filter%", "%$filter%"];
        }
        $rows = $this->db->query("SELECT * FROM packages $where ORDER BY name", $params);
        return array_map([Package::class, 'fromRow'], $rows);
    }

    public function search(string $query): array
    {
        // Search repo_packages
        $rows = $this->db->query(
            "SELECT rp.*, r.name as repo_name,
                    (SELECT 1 FROM packages p WHERE p.name = rp.name AND p.installed = 1) as installed
             FROM repo_packages rp
             JOIN repos r ON r.id = rp.repo_id AND r.enabled = 1
             WHERE rp.name LIKE ? OR rp.description LIKE ?
             GROUP BY rp.name
             ORDER BY rp.name",
            ["%$query%", "%$query%"]
        );
        return $rows;
    }

    public function isInstalled(string $name): bool
    {
        return $this->find($name) !== null;
    }

    public function isPinned(string $name): bool
    {
        $row = $this->db->query("SELECT 1 FROM pinned WHERE name = ?", [$name]);
        return !empty($row);
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public function register(Package $pkg, array $files = []): int
    {
        return $this->db->transaction(function(Database $db) use ($pkg, $files) {
            // Upsert package
            $db->exec(
                "INSERT INTO packages
                    (name, version, release, arch, description, url, license, size,
                     installed, install_reason, install_date, build_date, packager,
                     groups, provides, conflicts, replaces, checksum)
                 VALUES (?,?,?,?,?,?,?,?,1,?,datetime('now'),?,?,?,?,?,?,?)
                 ON CONFLICT(name, version, release) DO UPDATE SET
                    installed      = 1,
                    install_reason = excluded.install_reason,
                    install_date   = excluded.install_date",
                [
                    $pkg->name, $pkg->version, $pkg->release, $pkg->arch,
                    $pkg->description, $pkg->url, $pkg->license, $pkg->size,
                    $pkg->installReason, $pkg->buildDate, $pkg->packager,
                    implode(',', $pkg->groups),
                    implode(',', $pkg->provides),
                    implode(',', $pkg->conflicts),
                    implode(',', $pkg->replaces),
                    $pkg->checksum,
                ]
            );

            $id = $db->lastInsertId();
            if (!$id) {
                $row = $db->query("SELECT id FROM packages WHERE name=? AND version=? AND release=?",
                    [$pkg->name, $pkg->version, $pkg->release]);
                $id = $row[0]['id'];
            }

            // Store deps
            $db->exec("DELETE FROM dependencies WHERE package_id = ?", [$id]);
            foreach ($pkg->deps as $dep => $type) {
                $db->exec(
                    "INSERT OR IGNORE INTO dependencies (package_id, dep_name, dep_type) VALUES (?,?,?)",
                    [$id, $dep, $type]
                );
            }

            // Store files
            $db->exec("DELETE FROM files WHERE package_id = ?", [$id]);
            foreach ($files as $file) {
                $db->exec(
                    "INSERT OR IGNORE INTO files (package_id, path, checksum, file_type) VALUES (?,?,?,?)",
                    [$id, $file['path'], $file['checksum'] ?? null, $file['type'] ?? 'file']
                );
            }

            return $id;
        });
    }

    public function unregister(string $name): void
    {
        $this->db->exec(
            "UPDATE packages SET installed = 0 WHERE name = ? AND installed = 1",
            [$name]
        );
    }

    public function getFiles(string $name): array
    {
        $pkg = $this->find($name);
        if (!$pkg) return [];
        return $this->db->query(
            "SELECT f.* FROM files f JOIN packages p ON p.id = f.package_id
             WHERE p.name = ? AND p.installed = 1",
            [$name]
        );
    }

    public function findOwner(string $filepath): ?string
    {
        $row = $this->db->query(
            "SELECT p.name FROM files f
             JOIN packages p ON p.id = f.package_id AND p.installed = 1
             WHERE f.path = ?",
            [$filepath]
        );
        return $row[0]['name'] ?? null;
    }

    public function getDeps(string $name, string $type = 'required'): array
    {
        $pkg = $this->find($name);
        if (!$pkg) return [];
        $rows = $this->db->query(
            "SELECT dep_name, dep_version, dep_type FROM dependencies d
             JOIN packages p ON p.id = d.package_id AND p.installed = 1
             WHERE p.name = ? AND d.dep_type = ?",
            [$name, $type]
        );
        return $rows;
    }

    public function getReverseDeps(string $name): array
    {
        return $this->db->query(
            "SELECT p.name FROM dependencies d
             JOIN packages p ON p.id = d.package_id AND p.installed = 1
             WHERE d.dep_name = ? AND d.dep_type = 'required'",
            [$name]
        );
    }

    public function getInstalledList(): array
    {
        return $this->db->query(
            "SELECT name, version, release, arch, description, install_reason, install_date, size
             FROM packages WHERE installed = 1 ORDER BY name"
        );
    }

    public function getOrphanedPackages(): array
    {
        // Installed as 'dependency' but nothing requires them
        return $this->db->query(
            "SELECT p.name, p.version FROM packages p
             WHERE p.installed = 1 AND p.install_reason = 'dependency'
             AND p.name NOT IN (
                 SELECT d.dep_name FROM dependencies d
                 JOIN packages p2 ON p2.id = d.package_id AND p2.installed = 1
             )"
        );
    }

    public function pin(string $name, ?string $version = null, ?string $reason = null): void
    {
        $this->db->exec(
            "INSERT INTO pinned (name, version, reason) VALUES (?,?,?)
             ON CONFLICT(name) DO UPDATE SET version = excluded.version, reason = excluded.reason",
            [$name, $version, $reason]
        );
    }

    public function unpin(string $name): void
    {
        $this->db->exec("DELETE FROM pinned WHERE name = ?", [$name]);
    }

    public function getPinned(): array
    {
        return $this->db->query("SELECT * FROM pinned ORDER BY name");
    }

    public function logTransaction(
        string $action,
        string $package,
        ?string $oldVersion,
        ?string $newVersion,
        bool $success = true,
        ?string $reason = null
    ): void {
        $user = posix_getlogin() ?: 'unknown';
        $this->db->exec(
            "INSERT INTO transaction_log (action, package, old_version, new_version, user, reason, success)
             VALUES (?,?,?,?,?,?,?)",
            [$action, $package, $oldVersion, $newVersion, $user, $reason, $success ? 1 : 0]
        );
    }

    public function getLog(int $limit = 50): array
    {
        return $this->db->query(
            "SELECT * FROM transaction_log ORDER BY timestamp DESC LIMIT ?",
            [$limit]
        );
    }
}
