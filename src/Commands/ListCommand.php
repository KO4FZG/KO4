<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class ListCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $filter  = $args[0] ?? '';
        $verbose = isset($flags['v']) || isset($flags['verbose']);
        $explicit = isset($flags['explicit']) || isset($flags['e']);

        $pkgs = $this->registry->getInstalledList();

        if ($explicit) {
            $pkgs = array_filter($pkgs, fn($p) => $p['install_reason'] === 'explicit');
        }

        if ($filter) {
            $pkgs = array_filter($pkgs, fn($p) => str_contains($p['name'], $filter));
        }

        if (empty($pkgs)) {
            Terminal::dim("No packages installed.");
            return 0;
        }

        Terminal::header("Installed Packages (" . count($pkgs) . ")");

        if ($verbose) {
            $rows = array_map(fn($p) => [
                $p['name'],
                $p['version'] . '-' . ($p['release'] ?? 1),
                $p['arch'] ?? 'any',
                $p['install_reason'],
                Terminal::formatSize((int)$p['size']),
                substr($p['description'] ?? '', 0, 40),
            ], $pkgs);
            Terminal::table(['Name', 'Version', 'Arch', 'Reason', 'Size', 'Description'], $rows);
        } else {
            foreach ($pkgs as $p) {
                $reason = $p['install_reason'] === 'dependency' ? ' \033[2m[dep]\033[0m' : '';
                echo "  {$p['name']} \033[36m{$p['version']}\033[0m$reason\n";
            }
        }

        echo "\n";
        return 0;
    }
}

// ── Files ─────────────────────────────────────────────────────────────────────
