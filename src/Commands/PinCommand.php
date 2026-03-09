<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class PinCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            if ($this->commandName === 'pin') {
                $pinned = $this->registry->getPinned();
                if (empty($pinned)) {
                    Terminal::dim("No pinned packages.");
                    return 0;
                }
                Terminal::header("Pinned Packages");
                $rows = array_map(fn($p) => [
                    $p['name'],
                    $p['version'] ?? '*',
                    $p['reason'] ?? '',
                    $p['pinned_at'],
                ], $pinned);
                Terminal::table(['Package', 'Version', 'Reason', 'Pinned At'], $rows);
                return 0;
            }
            Terminal::error("Usage: ko4 {pin|unpin} <package>");
            return 1;
        }

        $name    = $args[0];
        $version = $flags['version'] ?? null;
        $reason  = $flags['reason'] ?? null;

        if ($this->commandName === 'unpin') {
            $this->registry->unpin($name);
            Terminal::info("$name unpinned.");
        } else {
            $this->registry->pin($name, $version, $reason);
            Terminal::info("$name pinned" . ($version ? " at $version" : '') . ".");
        }
        return 0;
    }
}

// ── Verify ────────────────────────────────────────────────────────────────────
