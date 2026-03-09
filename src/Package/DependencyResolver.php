<?php
declare(strict_types=1);

namespace Ko4\Package;

use Ko4\DependencyException;

class DependencyResolver
{
    private array $resolved   = [];
    private array $visiting   = [];
    private array $seen       = [];

    public function __construct(
        private PackageRegistry $registry
    ) {}

    /**
     * Resolve all dependencies for a list of packages.
     * Returns ordered install list (deepest deps first).
     */
    public function resolve(array $packageNames, bool $includeOptional = false): array
    {
        $this->resolved = [];
        $this->visiting = [];
        $this->seen     = [];

        foreach ($packageNames as $name) {
            $this->visit($name, $includeOptional, []);
        }

        return $this->resolved;
    }

    private function visit(string $name, bool $optional, array $chain): void
    {
        if (in_array($name, $this->resolved)) return;

        if (in_array($name, $this->visiting)) {
            $cycle = implode(' → ', [...$chain, $name]);
            throw new DependencyException("Circular dependency detected: $cycle");
        }

        // Already installed? skip (unless upgrading, handled by caller)
        if ($this->registry->isInstalled($name) && !in_array($name, $this->seen)) {
            $this->seen[] = $name;
            // Still recurse to ensure deps are tracked
        }

        $this->visiting[] = $name;

        // Find package in repos
        $pkg = $this->registry->findInRepo($name);
        if (!$pkg) {
            if ($this->registry->isInstalled($name)) {
                // Already satisfied by installed version
                array_pop($this->visiting);
                return;
            }
            throw new DependencyException("Package not found: $name");
        }

        // Recurse into dependencies
        foreach ($pkg->deps as $dep => $type) {
            if ($type === 'makedep') continue;
            if ($type === 'optional' && !$optional) continue;
            $this->visit($dep, $optional, [...$chain, $name]);
        }

        $key = array_search($name, $this->visiting);
        unset($this->visiting[$key]);

        if (!in_array($name, $this->resolved)) {
            $this->resolved[] = $name;
        }
    }

    /**
     * Build a dependency tree string for display.
     */
    public function buildTree(string $name, int $depth = 0, array $visited = []): string
    {
        $indent  = str_repeat('  ', $depth);
        $prefix  = $depth > 0 ? '└─ ' : '';
        $pkg     = $this->registry->find($name) ?? $this->registry->findInRepo($name);
        $ver     = $pkg ? $pkg->version : '?';
        $status  = $this->registry->isInstalled($name) ? "\033[32m[installed]\033[0m" : "\033[33m[missing]\033[0m";
        $line    = "$indent$prefix\033[1m$name\033[0m $ver $status\n";

        if (in_array($name, $visited) || $depth > 8) {
            return $line . ($depth > 0 ? "$indent  (see above)\n" : '');
        }

        $visited[] = $name;
        $deps = $pkg ? ($pkg->deps ?? []) : [];
        foreach ($deps as $dep => $type) {
            if ($type === 'optional') continue;
            $line .= $this->buildTree($dep, $depth + 1, $visited);
        }
        return $line;
    }

    /**
     * Get a flat list of all transitive dependencies.
     */
    public function getAllDeps(string $name, array &$seen = []): array
    {
        if (in_array($name, $seen)) return [];
        $seen[] = $name;

        $pkg = $this->registry->find($name) ?? $this->registry->findInRepo($name);
        if (!$pkg) return [];

        $all = [];
        foreach ($pkg->deps as $dep => $type) {
            if ($type === 'makedep') continue;
            $all[] = $dep;
            $all   = array_merge($all, $this->getAllDeps($dep, $seen));
        }
        return array_unique($all);
    }

    /**
     * Check if removing a package would break anything.
     */
    public function checkRemoveSafe(string $name): array
    {
        $rdeps = $this->registry->getReverseDeps($name);
        return array_column($rdeps, 'name');
    }

    /**
     * Build installation plan with size/action details.
     */
    public function buildInstallPlan(array $names, bool $upgrade = false): array
    {
        $plan = [];

        foreach ($names as $name) {
            $resolved = $this->resolve([$name]);
            foreach ($resolved as $depName) {
                if (isset($plan[$depName])) continue;

                $installed = $this->registry->find($depName);
                $available = $this->registry->findInRepo($depName);

                if (!$available && !$installed) {
                    throw new DependencyException("Cannot resolve: $depName");
                }

                if ($installed && !$upgrade) {
                    $plan[$depName] = [
                        'name'    => $depName,
                        'action'  => 'skip',
                        'version' => $installed->version,
                        'size'    => 0,
                    ];
                    continue;
                }

                if ($installed && $available) {
                    $cmp = Package::compareVersions($available->version, $installed->version);
                    if ($cmp <= 0 && !$upgrade) {
                        $plan[$depName] = ['name' => $depName, 'action' => 'skip',
                            'version' => $installed->version, 'size' => 0];
                        continue;
                    }
                    $plan[$depName] = [
                        'name'        => $depName,
                        'action'      => 'upgrade',
                        'old_version' => $installed->version,
                        'version'     => $available->version,
                        'size'        => $available->size,
                        'package'     => $available,
                    ];
                } elseif ($available) {
                    $isMain = in_array($name, $names);
                    $plan[$depName] = [
                        'name'    => $depName,
                        'action'  => 'install',
                        'version' => $available->version,
                        'size'    => $available->size,
                        'reason'  => $isMain ? 'explicit' : 'dependency',
                        'package' => $available,
                    ];
                }
            }
        }

        return $plan;
    }
}
