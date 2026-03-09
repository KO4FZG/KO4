<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class OwnsCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 owns <file>");
            return 1;
        }

        $file = realpath($args[0]) ?: $args[0];
        // Strip install root if present
        $root = $this->config->get('install_root', '/');
        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }
        if (!str_starts_with($file, '/')) $file = '/' . $file;

        $owner = $this->registry->findOwner($file);
        if (!$owner) {
            Terminal::warn("No package owns '$file'.");
            return 1;
        }

        Terminal::info("'$file' is owned by: \033[1m$owner\033[0m");
        return 0;
    }
}

// ── Deps ─────────────────────────────────────────────────────────────────────
