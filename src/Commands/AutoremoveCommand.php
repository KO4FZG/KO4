<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
