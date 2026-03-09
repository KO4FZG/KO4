<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class HelpCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        Terminal::banner();

        $commands = [
            'Package Management' => [
                'install  <pkg...>'         => 'Install packages (binary by default)',
                'remove   <pkg...>'         => 'Remove packages',
                'upgrade  [pkg...]'         => 'Upgrade packages (all if none given)',
                'downgrade <pkg> <version>' => 'Downgrade to a specific version',
                'reinstall <pkg...>'        => 'Reinstall packages',
                'autoremove'                => 'Remove orphaned dependency packages',
            ],
            'Build from Source' => [
                'build   <pkg...>'          => 'Build package from source using KO4BUILD',
                'rebuild <pkg...>'          => 'Force rebuild (ignore cache)',
                'create  <name>'            => 'Create a new KO4BUILD recipe scaffold',
                'pack    [dir]'             => 'Build package archive from current dir',
            ],
            'Query' => [
                'search  <query>'           => 'Search for packages in repos',
                'info    <pkg>'             => 'Show detailed package information',
                'list    [filter]'          => 'List installed packages',
                'files   <pkg>'             => 'List files owned by a package',
                'owns    <file>'            => 'Find which package owns a file',
                'deps    <pkg>'             => 'Show dependency tree',
                'rdeps   <pkg>'             => 'Show reverse dependencies',
            ],
            'Repositories' => [
                'sync    [repo]'            => 'Sync package indexes from repos',
                'repo add <name> <url>'     => 'Add a repository',
                'repo remove <name>'        => 'Remove a repository',
                'repo list'                 => 'List configured repositories',
                'repo enable/disable <n>'   => 'Enable or disable a repository',
                'repo index <dir>'          => 'Generate a repo index from .ko4pkg files',
            ],
            'Maintenance' => [
                'verify  [pkg...]'          => 'Check installed file integrity',
                'audit'                     => 'Check for available updates',
                'clean   [--all]'           => 'Clean package cache',
                'log     [pkg]'             => 'View transaction history',
                'pin     <pkg>'             => 'Pin a package (prevent upgrades)',
                'unpin   <pkg>'             => 'Unpin a package',
                'export  [file]'            => 'Export installed package list to JSON',
                'import  <file>'            => 'Install packages from exported list',
                'diff    <file>'            => 'Compare system with exported list',
            ],
        ];

        foreach ($commands as $section => $cmds) {
            echo "\033[1;34m$section\033[0m\n";
            foreach ($cmds as $cmd => $desc) {
                $padded = str_pad("  ko4 $cmd", 36);
                echo "\033[36m$padded\033[0m $desc\n";
            }
            echo "\n";
        }

        echo "\033[1mGlobal Flags\033[0m\n";
        echo "  \033[36m-y, --yes\033[0m                       Skip confirmation prompts\n";
        echo "  \033[36m--source, -s\033[0m                    Force build from source\n";
        echo "  \033[36m--verbose, -v\033[0m                   Verbose output\n";
        echo "  \033[36m--no-install\033[0m                    Build only, don't install\n";
        echo "  \033[36m--asdep\033[0m                         Mark install reason as dependency\n";
        echo "  \033[36m--cascade\033[0m                       Remove dependents too\n";
        echo "\n";

        return 0;
    }
}
