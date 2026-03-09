<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class FilesCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 files <package>");
            return 1;
        }

        $name  = $args[0];
        $files = $this->registry->getFiles($name);

        if (empty($files)) {
            if (!$this->registry->isInstalled($name)) {
                Terminal::error("Package '$name' is not installed.");
                return 1;
            }
            Terminal::dim("No files recorded for $name.");
            return 0;
        }

        Terminal::header("Files owned by $name");
        foreach ($files as $f) {
            $icon = match($f['file_type']) {
                'dir'     => "\033[34m[d]\033[0m",
                'symlink' => "\033[33m[l]\033[0m",
                default   => "   ",
            };
            echo "  $icon {$f['path']}\n";
        }
        echo "\n  " . count($files) . " file(s)\n\n";
        return 0;
    }
}

// ── Owns ──────────────────────────────────────────────────────────────────────
