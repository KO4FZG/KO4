<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Package\Installer;

class RemoveCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("No packages specified. Usage: ko4 remove <package...>");
            return 1;
        }

        $this->requireRoot();

        $cascade   = isset($flags['cascade'])   || isset($flags['c']);
        $keepFiles = isset($flags['keep-files']) || isset($flags['k']);

        $toRemove = [];

        foreach ($args as $name) {
            if (!$this->registry->isInstalled($name)) {
                Terminal::warn("Package '$name' is not installed.");
                continue;
            }

            if ($this->registry->isPinned($name)) {
                Terminal::error("Package '$name' is pinned and cannot be removed.");
                Terminal::dim("  Use 'ko4 unpin $name' to unpin it first.");
                continue;
            }

            // Check reverse dependencies
            $rdeps = $this->resolver->checkRemoveSafe($name);
            if (!empty($rdeps) && !$cascade) {
                Terminal::error("Cannot remove '$name': required by " . implode(', ', $rdeps));
                Terminal::dim("  Use --cascade to remove dependents too, or remove them first.");
                continue;
            }

            $toRemove[] = $name;

            if ($cascade && !empty($rdeps)) {
                foreach ($rdeps as $rdep) {
                    if (!in_array($rdep, $toRemove)) {
                        $toRemove[] = $rdep;
                    }
                }
            }
        }

        if (empty($toRemove)) {
            return 1;
        }

        Terminal::header("Packages to Remove");
        foreach ($toRemove as $name) {
            $pkg = $this->registry->find($name);
            echo "  \033[31m-\033[0m $name " . ($pkg ? $pkg->version : '') . "\n";
        }
        echo "\n";

        if (!$this->confirmAction("Proceed with removal?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        $installer = new Installer($this->config, $this->registry, $this->logger);
        $failed    = 0;

        foreach ($toRemove as $name) {
            Terminal::step("Removing $name...");
            $pkg = $this->registry->find($name);
            try {
                $installer->remove($name, $keepFiles);
                $this->registry->logTransaction('remove', $name, $pkg?->version, null);
                Terminal::info("$name removed.");
            } catch (\Throwable $e) {
                Terminal::error("Failed to remove $name: " . $e->getMessage());
                $this->registry->logTransaction('remove', $name, $pkg?->version, null, false, $e->getMessage());
                $failed++;
            }
        }

        if ($failed > 0) {
            Terminal::error("$failed package(s) failed to remove.");
            return 1;
        }

        Terminal::success("Removal complete.");
        return 0;
    }
}
