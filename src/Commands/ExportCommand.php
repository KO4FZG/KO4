<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class ExportCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $out     = $args[0] ?? 'ko4-packages.json';
        $pkgs    = $this->registry->getInstalledList();
        $explicit = array_filter($pkgs, fn($p) => $p['install_reason'] === 'explicit');

        $data = [
            'exported'  => date('Y-m-d\TH:i:s\Z'),
            'lux_version' => KO4_VERSION,
            'packages'  => array_values(array_map(fn($p) => [
                'name'    => $p['name'],
                'version' => $p['version'],
            ], $explicit)),
        ];

        file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT));
        Terminal::success("Exported " . count($explicit) . " packages to $out");
        return 0;
    }
}
