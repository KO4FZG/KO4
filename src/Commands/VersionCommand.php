<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
