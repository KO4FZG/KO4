<?php
declare(strict_types=1);

namespace Ko4\Core;

class Config
{
    private array $data = [];

    private const DEFAULTS = [
        'parallel_downloads' => 3,
        'verify_gpg'         => false,
        'build_dir'          => '/tmp/ko4-build',
        'install_root'       => '/',
        'keep_cache'         => true,
        'color'              => true,
        'progress'           => true,
        'default_arch'       => 'x86_64',
        'confirm'            => true,
        'log_level'          => 'info',
        'max_log_size'       => '10M',
        'source_dir'         => '/usr/src/ko4',
        'jobs'               => 'auto',
        'strip_binaries'     => true,
        'compress_man'       => true,
        'build_timeout'      => 3600,
    ];

    public function __construct(string $path)
    {
        $this->data = self::DEFAULTS;

        if (file_exists($path)) {
            $this->load($path);
        }
    }

    private function load(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_contains($line, '=')) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\"'");
                $this->data[$key] = $this->castValue($val);
            }
        }
    }

    private function castValue(string $val): mixed
    {
        if (strtolower($val) === 'true')  return true;
        if (strtolower($val) === 'false') return false;
        if (is_numeric($val))             return str_contains($val, '.') ? (float)$val : (int)$val;
        return $val;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function getJobs(): int
    {
        $j = $this->get('jobs', 'auto');
        if ($j === 'auto') {
            $cpus = (int)shell_exec('nproc 2>/dev/null') ?: 1;
            return max(1, $cpus);
        }
        return max(1, (int)$j);
    }
}
