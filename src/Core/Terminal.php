<?php
declare(strict_types=1);

namespace Ko4\Core;

class Terminal
{
    private static bool $color = true;
    private static bool $interactive = true;

    public static function init(bool $color, bool $interactive): void
    {
        self::$color       = $color && posix_isatty(STDOUT);
        self::$interactive = $interactive && posix_isatty(STDIN);
    }

    // ── Output helpers ────────────────────────────────────────────────────────

    public static function info(string $msg): void
    {
        echo self::colorize("\033[32m[✔]\033[0m", true) . " $msg\n";
    }

    public static function warn(string $msg): void
    {
        echo self::colorize("\033[33m[!]\033[0m", true) . " $msg\n";
    }

    public static function error(string $msg): void
    {
        fwrite(STDERR, self::colorize("\033[31m[✖]\033[0m", true) . " $msg\n");
    }

    public static function step(string $msg): void
    {
        echo self::colorize("\033[36m[→]\033[0m \033[1m$msg\033[0m", true) . "\n";
    }

    public static function dim(string $msg): void
    {
        echo self::colorize("\033[2m$msg\033[0m", true) . "\n";
    }

    public static function success(string $msg): void
    {
        echo self::colorize("\033[32m\033[1m[✔] $msg\033[0m", true) . "\n";
    }

    public static function header(string $msg): void
    {
        $line = str_repeat('─', min(80, strlen($msg) + 4));
        echo "\n" . self::colorize("\033[1;34m$line\033[0m", true) . "\n";
        echo self::colorize("\033[1;34m  $msg\033[0m", true) . "\n";
        echo self::colorize("\033[1;34m$line\033[0m", true) . "\n\n";
    }

    public static function banner(): void
    {
        $v = KO4_VERSION;
        $art = <<<EOT
  ██╗  ██╗ ██████╗ ██╗  ██╗
  ██║ ██╔╝██╔═══██╗██║  ██║
  █████╔╝ ██║   ██║███████║
  ██╔═██╗ ██║   ██║╚════██║
  ██║  ██╗╚██████╔╝     ██║
  ╚═╝  ╚═╝ ╚═════╝      ╚═╝
EOT;
        echo self::colorize("\033[1;34m$art\033[0m", true) . "\n";
        echo self::colorize("\033[2m  KO4OS Package Manager v$v\033[0m", true) . "\n\n";
    }

    // ── Progress bar ──────────────────────────────────────────────────────────

    public static function progress(string $label, int $current, int $total, int $width = 40): void
    {
        if (!self::$interactive) return;
        $pct  = $total > 0 ? (int)(($current / $total) * 100) : 0;
        $fill = $total > 0 ? (int)(($current / $total) * $width) : 0;
        $bar  = str_repeat('█', $fill) . str_repeat('░', $width - $fill);
        $label = str_pad(substr($label, 0, 30), 30);
        printf("\r  %s [%s] %3d%%", $label, $bar, $pct);
        if ($current >= $total) echo "\n";
    }

    public static function spinner(string $msg, callable $work): mixed
    {
        if (!self::$interactive) {
            echo "  $msg...\n";
            return $work();
        }
        $frames = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
        $i = 0;
        $done = false;
        $result = null;
        $error = null;

        // Run in same process (no async in PHP without extensions)
        // Show spinner before blocking call
        echo "\r  " . self::colorize("\033[36m" . $frames[0] . "\033[0m", true) . "  $msg";

        try {
            $result = $work(function() use (&$i, $frames, $msg) {
                $i = ($i + 1) % count($frames);
                echo "\r  " . self::colorize("\033[36m" . $frames[$i] . "\033[0m", true) . "  $msg";
            });
        } catch (\Throwable $e) {
            $error = $e;
        }

        echo "\r  " . ($error
            ? self::colorize("\033[31m✖\033[0m", true)
            : self::colorize("\033[32m✔\033[0m", true)
        ) . "  $msg\n";

        if ($error) throw $error;
        return $result;
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(array $headers, array $rows, array $colors = []): void
    {
        if (empty($rows)) {
            self::dim("  (no results)");
            return;
        }

        // Calculate column widths
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            $row = array_values($row);
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string)$cell));
            }
        }

        // Header
        $divider = '  ' . implode('  ', array_map(fn($w) => str_repeat('─', $w), $widths));
        echo $divider . "\n";
        $hdr = '  ' . implode('  ', array_map(
            fn($h, $w) => self::colorize("\033[1m" . str_pad($h, $w) . "\033[0m", true),
            $headers, $widths
        ));
        echo $hdr . "\n";
        echo $divider . "\n";

        // Rows
        foreach ($rows as $row) {
            $row = array_values($row);
            $cells = [];
            foreach ($row as $i => $cell) {
                $color  = $colors[$i] ?? null;
                $padded = str_pad((string)$cell, $widths[$i] ?? 0);
                $cells[] = $color
                    ? self::colorize("{$color}{$padded}\033[0m", true)
                    : $padded;
            }
            echo '  ' . implode('  ', $cells) . "\n";
        }
        echo $divider . "\n";
    }

    // ── Prompts ───────────────────────────────────────────────────────────────

    public static function confirm(string $question, bool $default = true): bool
    {
        if (!self::$interactive) return $default;
        $hint = $default ? '[Y/n]' : '[y/N]';
        echo self::colorize("\033[1;33m[?]\033[0m", true) . " $question $hint: ";
        $line = trim(fgets(STDIN) ?: '');
        if ($line === '') return $default;
        return strtolower($line[0]) === 'y';
    }

    public static function prompt(string $question, string $default = ''): string
    {
        $hint = $default !== '' ? " [$default]" : '';
        echo self::colorize("\033[1;33m[?]\033[0m", true) . " $question$hint: ";
        $line = trim(fgets(STDIN) ?: '');
        return $line !== '' ? $line : $default;
    }

    public static function select(string $question, array $options): int
    {
        echo self::colorize("\033[1;33m[?]\033[0m", true) . " $question\n";
        foreach ($options as $i => $opt) {
            echo "    " . self::colorize("\033[36m[" . ($i + 1) . "]\033[0m", true) . " $opt\n";
        }
        echo "  Choice: ";
        $line = (int)trim(fgets(STDIN) ?: '1');
        return max(1, min($line, count($options))) - 1;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    public static function formatSize(int $bytes): string
    {
        $units = ['B','KiB','MiB','GiB','TiB'];
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return round($n, 1) . ' ' . $units[$i];
    }

    public static function colorize(string $str, bool $strip = false): string
    {
        if (!self::$color || $strip === false) return $str;
        if (!self::$color) return preg_replace('/\033\[[0-9;]*m/', '', $str);
        return $str;
    }

    public static function isInteractive(): bool { return self::$interactive; }
}
