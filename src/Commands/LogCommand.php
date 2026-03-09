<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
