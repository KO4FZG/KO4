<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
