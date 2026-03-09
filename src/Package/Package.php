<?php
declare(strict_types=1);

namespace Ko4\Package;

class Package
{
    public int    $id          = 0;
    public string $name        = '';
    public string $version     = '';
    public int    $release     = 1;
    public string $arch        = 'any';
    public string $description = '';
    public string $url         = '';
    public string $license     = '';
    public int    $size        = 0;
    public bool   $installed   = false;
    public string $installReason = 'explicit'; // explicit | dependency
    public ?string $installDate = null;
    public ?string $buildDate   = null;
    public string  $packager   = '';
    public array   $groups     = [];
    public array   $provides   = [];
    public array   $conflicts  = [];
    public array   $replaces   = [];
    public ?string $checksum   = null;
    public array   $deps       = [];      // ['name' => 'required'|'optional'|'makedep']
    public array   $files      = [];
    public bool    $hasSource  = false;
    public bool    $hasBinary  = true;

    public static function fromRow(array $row): self
    {
        $p = new self();
        $p->id            = (int)($row['id'] ?? 0);
        $p->name          = $row['name'] ?? '';
        $p->version       = $row['version'] ?? '';
        $p->release       = (int)($row['release'] ?? 1);
        $p->arch          = $row['arch'] ?? 'any';
        $p->description   = $row['description'] ?? '';
        $p->url           = $row['url'] ?? '';
        $p->license       = $row['license'] ?? '';
        $p->size          = (int)($row['size'] ?? 0);
        $p->installed     = (bool)($row['installed'] ?? false);
        $p->installReason = $row['install_reason'] ?? 'explicit';
        $p->installDate   = $row['install_date'] ?? null;
        $p->buildDate     = $row['build_date'] ?? null;
        $p->packager      = $row['packager'] ?? '';
        $p->checksum      = $row['checksum'] ?? null;
        $p->groups        = self::splitList($row['groups'] ?? '');
        $p->provides      = self::splitList($row['provides'] ?? '');
        $p->conflicts     = self::splitList($row['conflicts'] ?? '');
        $p->replaces      = self::splitList($row['replaces'] ?? '');
        $p->hasSource     = (bool)($row['has_source'] ?? false);
        $p->hasBinary     = (bool)($row['has_binary'] ?? true);
        return $p;
    }

    public static function fromLuxpkg(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Package file not found: $path");
        }
        $data = json_decode(file_get_contents($path), true);
        if (!$data) {
            throw new \RuntimeException("Invalid package file: $path");
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $p = new self();
        foreach ($data as $k => $v) {
            $prop = lcfirst(str_replace('_', '', ucwords($k, '_')));
            if (property_exists($p, $prop)) {
                $p->$prop = $v;
            }
        }
        return $p;
    }

    public function fullVersion(): string
    {
        return "{$this->version}-{$this->release}";
    }

    public function toArray(): array
    {
        return [
            'name'           => $this->name,
            'version'        => $this->version,
            'release'        => $this->release,
            'arch'           => $this->arch,
            'description'    => $this->description,
            'url'            => $this->url,
            'license'        => $this->license,
            'size'           => $this->size,
            'packager'       => $this->packager,
            'groups'         => $this->groups,
            'provides'       => $this->provides,
            'conflicts'      => $this->conflicts,
            'replaces'       => $this->replaces,
            'checksum'       => $this->checksum,
            'deps'           => $this->deps,
            'has_source'     => $this->hasSource,
            'has_binary'     => $this->hasBinary,
        ];
    }

    /**
     * Compare two version strings.
     * Returns -1, 0, 1 (like strcmp/spaceship).
     */
    public static function compareVersions(string $a, string $b): int
    {
        // Split on non-alphanumeric separators
        $pa = preg_split('/([^a-zA-Z0-9])/', $a, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $pb = preg_split('/([^a-zA-Z0-9])/', $b, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $va = $pa[$i] ?? '0';
            $vb = $pb[$i] ?? '0';

            if (is_numeric($va) && is_numeric($vb)) {
                $cmp = (int)$va <=> (int)$vb;
            } else {
                $cmp = strcmp($va, $vb);
            }

            if ($cmp !== 0) return $cmp;
        }
        return 0;
    }

    public function satisfies(string $constraint): bool
    {
        // e.g. ">=2.0", "=1.5", "~1.2", "*"
        if ($constraint === '*' || $constraint === '') return true;

        if (preg_match('/^([><=!~]+)\s*(.+)$/', $constraint, $m)) {
            $op  = $m[1];
            $ver = $m[2];
            $cmp = self::compareVersions($this->version, $ver);
            return match($op) {
                '>='  => $cmp >= 0,
                '<='  => $cmp <= 0,
                '>'   => $cmp >  0,
                '<'   => $cmp <  0,
                '=','=='=> $cmp === 0,
                '!='  => $cmp !== 0,
                '~'   => str_starts_with($this->version, rtrim($ver, '*')),
                default => true,
            };
        }
        return version_compare($this->version, $constraint, '>=');
    }

    private static function splitList(string $str): array
    {
        if ($str === '') return [];
        return array_filter(array_map('trim', explode(',', $str)));
    }
}
