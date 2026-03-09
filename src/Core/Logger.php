<?php
declare(strict_types=1);

namespace Ko4\Core;

class Logger
{
    private string $path;
    private int    $maxBytes;

    public function __construct(string $path, string $maxSize = '10M')
    {
        $this->path     = $path;
        $this->maxBytes = $this->parseSize($maxSize);

        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }

    public function info(string $msg): void  { $this->write('INFO',  $msg); }
    public function warn(string $msg): void  { $this->write('WARN',  $msg); }
    public function error(string $msg): void { $this->write('ERROR', $msg); }
    public function debug(string $msg): void { $this->write('DEBUG', $msg); }

    private function write(string $level, string $msg): void
    {
        $this->rotate();
        $ts   = date('Y-m-d H:i:s');
        $user = posix_getlogin() ?: 'unknown';
        $line = "[{$ts}] [{$level}] [{$user}] {$msg}\n";
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function rotate(): void
    {
        if (!file_exists($this->path)) return;
        if (filesize($this->path) < $this->maxBytes) return;

        $rotated = $this->path . '.' . date('Ymd-His');
        rename($this->path, $rotated);
        // Keep only last 5 rotated logs
        $old = glob($this->path . '.*');
        if ($old && count($old) > 5) {
            sort($old);
            array_map('unlink', array_slice($old, 0, count($old) - 5));
        }
    }

    private function parseSize(string $size): int
    {
        $unit = strtoupper(substr($size, -1));
        $num  = (int)$size;
        return match($unit) {
            'G' => $num * 1024 * 1024 * 1024,
            'M' => $num * 1024 * 1024,
            'K' => $num * 1024,
            default => $num,
        };
    }
}
