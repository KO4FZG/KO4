<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Build\Builder;

class BuildCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("No packages specified. Usage: ko4 build <package...>");
            return 1;
        }

        $this->requireRoot();

        $force    = $this->commandName === 'rebuild' || isset($flags['force']) || isset($flags['f']);
        $install  = !isset($flags['no-install']);
        $clean    = !isset($flags['no-clean']);
        $version  = $flags['version'] ?? null;

        $builder  = new Builder($this->config, $this->logger);
        $failed   = 0;

        foreach ($args as $name) {
            Terminal::header("Building: $name");

            // Find build script
            $script = $this->findBuildScript($name);
            if (!$script) {
                Terminal::error("No KO4BUILD script found for '$name'.");
                Terminal::dim("  Search path: " . KO4_HOME . "/recipes/$name/KO4BUILD");
                Terminal::dim("  Create one with: ko4 create $name");
                $failed++;
                continue;
            }

            try {
                $pkg = $builder->buildFromScript($script, $force, $version);

                if ($install) {
                    Terminal::step("Installing built package...");
                    $installer = new \Ko4\Package\Installer($this->config, $this->registry, $this->logger);
                    $installer->installFile($pkg['path'], 'explicit');
                    $this->registry->logTransaction('build-install', $name, null, $pkg['version']);
                    Terminal::success("$name {$pkg['version']} built and installed.");
                } else {
                    Terminal::success("$name {$pkg['version']} built: {$pkg['path']}");
                }
            } catch (\Throwable $e) {
                Terminal::error("Build failed for $name: " . $e->getMessage());
                if (isset($flags['v']) || isset($flags['verbose'])) {
                    echo $e->getTraceAsString() . "\n";
                }
                $failed++;
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    private function findBuildScript(string $name): ?string
    {
        $paths = [
            KO4_HOME . "/recipes/$name/KO4BUILD",
            KO4_HOME . "/recipes/$name.KO4BUILD",
            getcwd() . "/KO4BUILD",
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }
}
