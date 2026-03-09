<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class DiffCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 diff <packages.json>");
            return 1;
        }

        $file = $args[0];
        if (!file_exists($file)) {
            Terminal::error("File not found: $file");
            return 1;
        }

        $data      = json_decode(file_get_contents($file), true);
        $saved     = array_column($data['packages'] ?? [], 'version', 'name');
        $installed = array_column($this->registry->getInstalledList(), 'version', 'name');

        $onlyInSaved     = array_diff_key($saved, $installed);
        $onlyInstalled   = array_diff_key($installed, $saved);
        $versionMismatch = [];

        foreach ($saved as $name => $ver) {
            if (isset($installed[$name]) && $installed[$name] !== $ver) {
                $versionMismatch[$name] = ['saved' => $ver, 'installed' => $installed[$name]];
            }
        }

        Terminal::header("Package Diff: $file vs current");

        if ($onlyInSaved) {
            echo "\033[33m  Missing from system:\033[0m\n";
            foreach ($onlyInSaved as $n => $v) echo "    - $n $v\n";
            echo "\n";
        }
        if ($onlyInstalled) {
            echo "\033[36m  Not in saved set:\033[0m\n";
            foreach ($onlyInstalled as $n => $v) echo "    + $n $v\n";
            echo "\n";
        }
        if ($versionMismatch) {
            echo "\033[35m  Version mismatches:\033[0m\n";
            foreach ($versionMismatch as $n => $v) {
                echo "    ~ $n saved={$v['saved']} installed={$v['installed']}\n";
            }
            echo "\n";
        }

        if (!$onlyInSaved && !$onlyInstalled && !$versionMismatch) {
            Terminal::success("System matches saved package set exactly.");
        }

        return 0;
    }
}

// ── Version ───────────────────────────────────────────────────────────────────
