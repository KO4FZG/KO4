<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class DepsCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 deps <package> | ko4 rdeps <package>");
            return 1;
        }

        $name = $args[0];

        if ($this->commandName === 'rdeps') {
            $rdeps = $this->registry->getReverseDeps($name);
            if (empty($rdeps)) {
                Terminal::info("No packages depend on '$name'.");
                return 0;
            }
            Terminal::header("Packages that depend on $name");
            foreach ($rdeps as $r) {
                echo "  → {$r['name']}\n";
            }
        } else {
            Terminal::header("Dependency tree for $name");
            $tree = $this->resolver->buildTree($name);
            echo $tree;
        }

        echo "\n";
        return 0;
    }
}

// ── Sync ──────────────────────────────────────────────────────────────────────
