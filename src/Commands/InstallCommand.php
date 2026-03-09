<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Package\Package;
use Ko4\Package\Installer;
use Ko4\PackageNotFoundException;

class InstallCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("No packages specified. Usage: ko4 install <package...>");
            return 1;
        }

        $this->requireRoot();

        $reinstall   = $this->commandName === 'reinstall';
        $fromSource  = isset($flags['source']) || isset($flags['s']);
        $noConfirm   = isset($flags['y']) || isset($flags['yes']);
        $optional    = isset($flags['optional']);
        $asDep       = isset($flags['asdep']);

        // Resolve install plan
        Terminal::step("Resolving dependencies...");
        try {
            $plan = $this->resolver->buildInstallPlan($args, $reinstall);
        } catch (\Ko4\DependencyException $e) {
            Terminal::error($e->getMessage());
            return 1;
        }

        $actionable = array_filter($plan, fn($p) => $p['action'] !== 'skip');

        if (empty($actionable) && !$reinstall) {
            Terminal::success("All packages are already up to date.");
            return 0;
        }

        // Display plan
        $this->printInstallPlan($plan);

        // Confirm
        if (!$this->confirmAction("Proceed with installation?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        // Execute plan
        $installer = new Installer($this->config, $this->registry, $this->logger);
        $failed    = 0;

        foreach ($actionable as $item) {
            $pkg = $item['package'];
            if ($asDep) $pkg->installReason = 'dependency';

            Terminal::step("Installing {$pkg->name} {$pkg->version}...");

            try {
                if ($fromSource || (!$pkg->hasBinary && $pkg->hasSource)) {
                    $builder = new \Ko4\Build\Builder($this->config, $this->logger);
                    $pkgFile = $builder->build($pkg);
                    $installer->installFile($pkgFile, $pkg->installReason);
                } else {
                    $installer->install($pkg);
                }

                $oldVersion = $item['old_version'] ?? null;
                $this->registry->logTransaction(
                    $item['action'], $pkg->name, $oldVersion, $pkg->version, true
                );
                Terminal::info("{$pkg->name} {$pkg->version} installed.");
            } catch (\Throwable $e) {
                Terminal::error("Failed to install {$pkg->name}: " . $e->getMessage());
                $this->registry->logTransaction(
                    $item['action'], $pkg->name, null, $pkg->version, false, $e->getMessage()
                );
                $failed++;
            }
        }

        if ($failed > 0) {
            Terminal::error("$failed package(s) failed to install.");
            return 1;
        }

        Terminal::success("Installation complete.");
        return 0;
    }
}
