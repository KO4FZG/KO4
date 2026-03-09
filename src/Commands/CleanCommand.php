<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class CleanCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        $all     = isset($flags['all']) || isset($flags['a']);
        $cacheDir = KO4_CACHE . '/packages';

        if (!is_dir($cacheDir)) {
            Terminal::info("Cache is already empty.");
            return 0;
        }

        $files = glob($cacheDir . '/*.ko4pkg') ?: [];
        if (empty($files)) {
            Terminal::info("Nothing to clean.");
            return 0;
        }

        // Keep only newest version per package if not --all
        $toDelete = [];
        if ($all) {
            $toDelete = $files;
        } else {
            $byName = [];
            foreach ($files as $f) {
                preg_match('/([^\/]+)-(\d.+?)\.ko4pkg$/', basename($f), $m);
                $byName[$m[1] ?? $f][] = $f;
            }
            foreach ($byName as $name => $versions) {
                sort($versions);
                array_pop($versions); // keep newest
                $toDelete = array_merge($toDelete, $versions);
            }
        }

        if (empty($toDelete)) {
            Terminal::info("Nothing to clean (only newest versions kept).");
            return 0;
        }

        $size = array_sum(array_map('filesize', $toDelete));
        echo "\n  Will delete " . count($toDelete) . " file(s), freeing " . Terminal::formatSize($size) . "\n\n";

        if (!$this->confirmAction("Proceed?", $flags)) {
            Terminal::warn("Aborted.");
            return 0;
        }

        foreach ($toDelete as $f) {
            unlink($f);
        }
        Terminal::success("Freed " . Terminal::formatSize($size) . ".");
        return 0;
    }
}

// ── Autoremove ────────────────────────────────────────────────────────────────
