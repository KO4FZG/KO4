<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
