<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
