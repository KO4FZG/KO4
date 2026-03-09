<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Config;
use Ko4\Core\Database;
use Ko4\Core\Logger;
use Ko4\Core\Terminal;
use Ko4\Package\PackageRegistry;
use Ko4\Package\DependencyResolver;

abstract class AbstractCommand
{
    protected Config            $config;
    protected Database          $db;
    protected Logger            $logger;
    protected PackageRegistry   $registry;
    protected DependencyResolver $resolver;
    protected string            $commandName = '';

    public function __construct(Config $config, Database $db, Logger $logger)
    {
        $this->config   = $config;
        $this->db       = $db;
        $this->logger   = $logger;
        $this->registry = new PackageRegistry($db);
        $this->resolver = new DependencyResolver($this->registry);

        Terminal::init(
            (bool)$config->get('color', true),
            (bool)$config->get('interactive', true)
        );
    }

    abstract public function execute(array $args, array $flags): int;

    public function setCommandName(string $name): void
    {
        $this->commandName = $name;
    }

    protected function requireRoot(): void
    {
        if (posix_getuid() !== 0) {
            throw new \Ko4\PermissionException("This command requires root privileges. Run with sudo.");
        }
    }

    protected function confirmAction(string $message, array $flags): bool
    {
        if (isset($flags['y']) || isset($flags['yes']) || !$this->config->get('confirm')) {
            return true;
        }
        return Terminal::confirm($message);
    }

    /**
     * Create an Installer, honouring any --root= override already set in config.
     */
    protected function makeInstaller(): \Ko4\Package\Installer
    {
        return new \Ko4\Package\Installer($this->config, $this->registry, $this->logger);
    }

    /**
     * Show the active install root if it differs from /.
     */
    protected function showRootBanner(): void
    {
        $root = $this->config->get('install_root', '/');
        if ($root !== '/' && $root !== '') {
            Terminal::warn("Target root: [1m$root[0m");
        }
    }

    protected function printInstallPlan(array $plan): void
    {
        $toInstall = array_filter($plan, fn($p) => $p['action'] === 'install');
        $toUpgrade = array_filter($plan, fn($p) => $p['action'] === 'upgrade');
        $toSkip    = array_filter($plan, fn($p) => $p['action'] === 'skip');

        if ($toInstall) {
            Terminal::header("Packages to Install (" . count($toInstall) . ")");
            $rows = array_map(fn($p) => [
                $p['name'],
                $p['version'],
                Terminal::formatSize($p['size']),
                $p['reason'] ?? 'explicit',
            ], $toInstall);
            Terminal::table(['Package', 'Version', 'Size', 'Reason'], $rows);
        }

        if ($toUpgrade) {
            Terminal::header("Packages to Upgrade (" . count($toUpgrade) . ")");
            $rows = array_map(fn($p) => [
                $p['name'],
                ($p['old_version'] ?? '?') . ' → ' . $p['version'],
                Terminal::formatSize($p['size']),
            ], $toUpgrade);
            Terminal::table(['Package', 'Version Change', 'Size'], $rows);
        }

        $totalSize = array_sum(array_column($plan, 'size'));
        echo "\n  Total download size: " . Terminal::formatSize($totalSize) . "\n";

        if ($toSkip) {
            Terminal::dim("  " . count($toSkip) . " package(s) already up to date.");
        }
    }
}
