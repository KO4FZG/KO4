<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
