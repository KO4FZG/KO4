<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class InfoCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 info <package>");
            return 1;
        }

        $name = $args[0];
        $pkg  = $this->registry->find($name) ?? $this->registry->findInRepo($name);

        if (!$pkg) {
            Terminal::error("Package '$name' not found.");
            return 1;
        }

        Terminal::header("Package: $name");

        $installed = $this->registry->isInstalled($name);
        $pinned    = $this->registry->isPinned($name);

        $fields = [
            ['Name',        $pkg->name],
            ['Version',     $pkg->fullVersion()],
            ['Arch',        $pkg->arch],
            ['Description', $pkg->description],
            ['URL',         $pkg->url],
            ['License',     $pkg->license],
            ['Groups',      implode(', ', $pkg->groups) ?: '(none)'],
            ['Provides',    implode(', ', $pkg->provides) ?: '(none)'],
            ['Conflicts',   implode(', ', $pkg->conflicts) ?: '(none)'],
            ['Installed',   $installed ? "\033[32myes\033[0m" : "\033[33mno\033[0m"],
            ['Pinned',      $pinned    ? "\033[33myes\033[0m" : 'no'],
            ['Size',        Terminal::formatSize($pkg->size)],
        ];

        if ($installed) {
            $fields[] = ['Install Date', $pkg->installDate ?? '?'];
            $fields[] = ['Install Reason', $pkg->installReason];
        }

        if ($pkg->buildDate) {
            $fields[] = ['Build Date', $pkg->buildDate];
        }

        $labelWidth = 15;
        foreach ($fields as [$label, $value]) {
            $lpad = str_pad($label . ':', $labelWidth);
            echo "  \033[1m$lpad\033[0m $value\n";
        }

        // Dependencies
        echo "\n";
        if (!empty($pkg->deps)) {
            $required = array_keys(array_filter($pkg->deps, fn($t) => $t === 'required'));
            $optional = array_keys(array_filter($pkg->deps, fn($t) => $t === 'optional'));
            $makedeps = array_keys(array_filter($pkg->deps, fn($t) => $t === 'makedep'));

            if ($required) {
                echo "  \033[1mDependencies:\033[0m   " . implode(', ', $required) . "\n";
            }
            if ($optional) {
                echo "  \033[1mOptional Deps:\033[0m  " . implode(', ', $optional) . "\n";
            }
            if ($makedeps) {
                echo "  \033[1mBuild Deps:\033[0m     " . implode(', ', $makedeps) . "\n";
            }
        }

        echo "\n";
        return 0;
    }
}

// ── List ──────────────────────────────────────────────────────────────────────
