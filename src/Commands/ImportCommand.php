<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class ImportCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 import <packages.json>");
            return 1;
        }

        $this->requireRoot();

        $file = $args[0];
        if (!file_exists($file)) {
            Terminal::error("File not found: $file");
            return 1;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['packages'])) {
            Terminal::error("Invalid export file.");
            return 1;
        }

        $names = array_column($data['packages'], 'name');
        Terminal::info("Importing " . count($names) . " packages from $file");

        // Delegate to install command
        $install = new InstallCommand($this->config, $this->db, $this->logger);
        $install->setCommandName('install');
        return $install->execute($names, $flags);
    }
}

// ── Diff ──────────────────────────────────────────────────────────────────────
