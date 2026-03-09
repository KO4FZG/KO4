<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Package\Installer;

class DowngradeCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (count($args) < 2) {
            Terminal::error("Usage: ko4 downgrade <package> <version>");
            return 1;
        }

        $this->requireRoot();

        [$name, $version] = $args;

        // Look for cached .ko4pkg file
        $cache = KO4_CACHE . "/packages/{$name}-{$version}*.ko4pkg";
        $files = glob($cache);

        if (empty($files)) {
            Terminal::error("No cached package found for $name $version.");
            Terminal::dim("  Cached packages are kept in " . KO4_CACHE . "/packages/");
            Terminal::dim("  You may need to build from source: ko4 build $name --version=$version");
            return 1;
        }

        Terminal::step("Downgrading $name to $version...");

        if (!$this->confirmAction("Downgrade $name to $version?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        $installer  = new Installer($this->config, $this->registry, $this->logger);
        $current    = $this->registry->find($name);

        try {
            $installer->installFile($files[0]);
            $this->registry->logTransaction(
                'downgrade', $name, $current?->version, $version
            );
            Terminal::success("$name downgraded to $version.");
            return 0;
        } catch (\Throwable $e) {
            Terminal::error("Downgrade failed: " . $e->getMessage());
            return 1;
        }
    }
}
