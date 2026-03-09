<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class VerifyCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $packages = empty($args) ? array_column($this->registry->getInstalledList(), 'name') : $args;
        $errors   = 0;

        Terminal::header("Verifying package integrity...");

        foreach ($packages as $name) {
            $files  = $this->registry->getFiles($name);
            $broken = [];

            foreach ($files as $f) {
                if ($f['file_type'] !== 'file') continue;
                $path = $this->config->get('install_root', '/') . $f['path'];
                if (!file_exists($path)) {
                    $broken[] = ['missing', $f['path']];
                } elseif ($f['checksum']) {
                    $actual = hash_file('sha256', $path);
                    if ($actual !== $f['checksum']) {
                        $broken[] = ['modified', $f['path']];
                    }
                }
            }

            if (empty($broken)) {
                echo "  \033[32m✔\033[0m $name\n";
            } else {
                echo "  \033[31m✖\033[0m $name\n";
                foreach ($broken as [$status, $path]) {
                    echo "      \033[31m[$status]\033[0m $path\n";
                }
                $errors++;
            }
        }

        echo "\n";
        if ($errors > 0) {
            Terminal::warn("$errors package(s) have integrity issues. Run 'ko4 reinstall <pkg>' to fix.");
            return 1;
        }
        Terminal::success("All packages OK.");
        return 0;
    }
}

// ── Audit ─────────────────────────────────────────────────────────────────────
