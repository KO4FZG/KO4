<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Package\Installer;

class UpgradeCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $this->requireRoot();

        // If specific packages given, upgrade those; otherwise upgrade all
        if (empty($args)) {
            $installed = $this->registry->getInstalledList();
            $args      = array_column($installed, 'name');
        }

        if (empty($args)) {
            Terminal::warn("No packages installed.");
            return 0;
        }

        Terminal::step("Checking for upgrades...");

        // Filter to only those with a newer version available
        $upgradable = [];
        foreach ($args as $name) {
            $installed = $this->registry->find($name);
            $available = $this->registry->findInRepo($name);
            if (!$installed || !$available) continue;
            if ($this->registry->isPinned($name)) {
                Terminal::dim("  Skipping pinned package: $name");
                continue;
            }
            if (\Ko4\Package\Package::compareVersions($available->version, $installed->version) > 0) {
                $upgradable[] = $name;
            }
        }

        if (empty($upgradable)) {
            Terminal::success("All packages are up to date.");
            return 0;
        }

        Terminal::step("Building upgrade plan...");
        $plan = $this->resolver->buildInstallPlan($upgradable, true);
        $this->printInstallPlan($plan);

        if (!$this->confirmAction("Proceed with upgrade?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        $installer = new Installer($this->config, $this->registry, $this->logger);
        $failed    = 0;

        foreach ($plan as $item) {
            if ($item['action'] === 'skip') continue;
            $pkg = $item['package'];
            Terminal::step("Upgrading {$pkg->name} {$item['old_version']} → {$pkg->version}...");
            try {
                $installer->install($pkg);
                $this->registry->logTransaction(
                    'upgrade', $pkg->name, $item['old_version'], $pkg->version
                );
                Terminal::info("{$pkg->name} upgraded to {$pkg->version}.");
            } catch (\Throwable $e) {
                Terminal::error("Failed to upgrade {$pkg->name}: " . $e->getMessage());
                $failed++;
            }
        }

        if ($failed > 0) {
            Terminal::error("$failed package(s) failed to upgrade.");
            return 1;
        }

        Terminal::success("Upgrade complete.");
        return 0;
    }
}
