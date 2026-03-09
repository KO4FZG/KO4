<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

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
