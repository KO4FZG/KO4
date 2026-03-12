<?php
declare(strict_types=1);

namespace Ko4\Build;

use Ko4\Core\Config;
use Ko4\Core\Logger;
use Ko4\Core\Terminal;
use Ko4\BuildException;
use Ko4\Package\Package;

/**
 * Executes KO4BUILD scripts to build packages from source.
 *
 * A KO4BUILD is a PHP-like INI+Bash hybrid:
 *
 *   [meta]
 *   name = curl
 *   version = 8.6.0
 *   release = 1
 *   description = Command line tool for transferring data with URLs
 *   url = https://curl.se
 *   license = MIT
 *   arch = x86_64
 *   deps = openssl, zlib
 *   makedeps = cmake, perl
 *
 *   [sources]
 *   https://curl.se/download/curl-${version}.tar.gz sha256:abc123...
 *
 *   [build]
 *   #!/bin/bash
 *   ./configure --prefix=/usr --with-openssl
 *   make -j${JOBS}
 *
 *   [package]
 *   #!/bin/bash
 *   make DESTDIR="$PKGDIR" install
 *   install -Dm644 COPYING "$PKGDIR/usr/share/licenses/$name/LICENSE"
 */
class Builder
{
    private string $buildDir;
    private int    $jobs;

    public function __construct(
        private Config $config,
        private Logger $logger
    ) {
        $this->buildDir = $config->get('build_dir', '/tmp/ko4-build');
        $this->jobs     = $config->getJobs();
    }

    public function buildFromScript(string $scriptPath, bool $force = false, ?string $overrideVersion = null): array
    {
        $script  = $this->parseScript($scriptPath);
        $meta    = $script['meta'];
        $name    = $meta['name']    ?? throw new BuildException("KO4BUILD missing 'name'");
        $version = $overrideVersion ?? ($meta['version'] ?? throw new BuildException("KO4BUILD missing 'version'"));
        $release = (int)($meta['release'] ?? 1);

        $pkgFile = KO4_CACHE . "/packages/{$name}-{$version}-{$release}.ko4pkg";

        if (!$force && file_exists($pkgFile)) {
            Terminal::dim("  Using cached build: $pkgFile");
            return ['path' => $pkgFile, 'version' => $version, 'name' => $name];
        }

        $workDir = $this->buildDir . "/{$name}-{$version}-" . time();
        $srcDir  = $workDir . '/src';
        $pkgDir  = $workDir . '/pkg';

        @mkdir($srcDir, 0755, true);
        @mkdir($pkgDir, 0755, true);
        @mkdir(dirname($pkgFile), 0755, true);

        Terminal::step("Build environment: $workDir");

        try {
            // 1. Download and verify sources
            if (!empty($script['sources'])) {
                Terminal::step("Fetching sources...");
                $this->fetchSources($script['sources'], $srcDir, $version);
            }

            // 2. Extract and prepare
            Terminal::step("Preparing sources...");
            $this->extractSources($srcDir);

            // 3. Run prepare() if defined
            if (!empty($script['prepare'])) {
                Terminal::step("Running prepare()...");
                $this->runShellSection($script['prepare'], $srcDir, $pkgDir, $meta, $this->jobs);
            }

            // 4. Run build()
            if (!empty($script['build'])) {
                Terminal::step("Building...");
                $this->runShellSection($script['build'], $srcDir, $pkgDir, $meta, $this->jobs);
            }

            // 5. Run check() (optional test suite)
            if (!empty($script['check']) && $this->config->get('run_tests', false)) {
                Terminal::step("Running tests...");
                $this->runShellSection($script['check'], $srcDir, $pkgDir, $meta, $this->jobs);
            }

            // 6. Run package()
            if (!empty($script['package'])) {
                Terminal::step("Packaging...");
                $this->runShellSection($script['package'], $srcDir, $pkgDir, $meta, $this->jobs);
            }

            // 7. Verify PKGDIR is not empty — catch DESTDIR mistakes early
            $this->assertPkgDirNotEmpty($pkgDir, $meta['name'] ?? 'unknown');

            // 8. Post-process: strip, compress man pages
            $this->postProcess($pkgDir);

            // 8. Create .ko4pkg archive
            Terminal::step("Creating package archive...");
            $this->createArchive($pkgDir, $pkgFile, $meta, $scriptPath);

            return ['path' => $pkgFile, 'version' => $version, 'name' => $name];

        } catch (\Throwable $e) {
            // Delete any partial .ko4pkg so a retry does a clean build
            if (file_exists($pkgFile)) {
                unlink($pkgFile);
                Terminal::dim("  Removed partial package cache.");
            }
            throw $e;
        } finally {
            if ($this->config->get('keep_build_dir', false) === false) {
                $this->rmdirRecursive($workDir);
            } else {
                Terminal::dim("  Build directory kept at: $workDir");
                Terminal::dim("  Check \$PKGDIR at: $pkgDir");
            }
        }
    }

    public function build(Package $pkg): string
    {
        // Find the KO4BUILD script
        $script = KO4_HOME . "/recipes/{$pkg->name}/KO4BUILD";
        if (!file_exists($script)) {
            throw new BuildException("No KO4BUILD script for {$pkg->name}");
        }
        $result = $this->buildFromScript($script);
        return $result['path'];
    }

    private function parseScript(string $path): array
    {
        $content  = file_get_contents($path);
        $sections = [];
        $current  = 'meta';
        $buffer   = '';

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\[([a-z_]+)\]\s*$/', $line, $m)) {
                $sections[$current] = trim($buffer);
                $current = $m[1];
                $buffer  = '';
            } else {
                $buffer .= $line . "\n";
            }
        }
        $sections[$current] = trim($buffer);

        // Parse [meta] as key=value
        $meta = [];
        foreach (explode("\n", $sections['meta'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $meta[trim($k)] = trim($v);
            }
        }

        // Parse deps, makedeps as arrays
        foreach (['deps', 'makedeps', 'optdeps', 'provides', 'conflicts'] as $arr) {
            if (isset($meta[$arr])) {
                $meta[$arr] = array_map('trim', explode(',', $meta[$arr]));
            }
        }

        // Parse [sources]
        $sources = [];
        foreach (explode("\n", $sections['sources'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts    = preg_split('/\s+/', $line, 2);
            $sources[] = [
                'url'      => $parts[0],
                'checksum' => $parts[1] ?? null,
            ];
        }

        return [
            'meta'    => $meta,
            'sources' => $sources,
            'prepare' => $sections['prepare'] ?? '',
            'build'   => $sections['build']   ?? '',
            'check'   => $sections['check']   ?? '',
            'package' => $sections['package'] ?? '',
        ];
    }

    private function fetchSources(array $sources, string $destDir, string $version): void
    {
        foreach ($sources as $src) {
            $url  = str_replace('${version}', $version, $src['url']);
            $file = $destDir . '/' . basename($url);

            Terminal::dim("  Downloading: $url");

            // Use curl or wget
            if (is_executable('/usr/bin/curl')) {
                $cmd = "curl -# -L -o " . escapeshellarg($file) . " " . escapeshellarg($url);
            } elseif (is_executable('/usr/bin/wget')) {
                $cmd = "wget -q --show-progress -O " . escapeshellarg($file) . " " . escapeshellarg($url);
            } else {
                throw new BuildException("Neither curl nor wget found for downloading sources.");
            }

            $ret = null;
            passthru($cmd, $ret);
            if ($ret !== 0) throw new BuildException("Failed to download: $url");

            // Verify checksum
            if ($src['checksum']) {
                [$algo, $expected] = explode(':', $src['checksum'], 2);
                $actual = hash_file($algo, $file);
                if ($actual !== $expected) {
                    throw new BuildException("Checksum mismatch for " . basename($url) .
                        " (expected $expected, got $actual)");
                }
                Terminal::dim("  Checksum verified ($algo).");
            }
        }
    }

    private function extractSources(string $srcDir): void
    {
        foreach (glob($srcDir . '/*') as $file) {
            $ext  = strtolower($file);
            $dest = $srcDir;

            if (str_ends_with($ext, '.tar.gz') || str_ends_with($ext, '.tgz')) {
                exec("tar -xzf " . escapeshellarg($file) . " -C " . escapeshellarg($dest));
            } elseif (str_ends_with($ext, '.tar.bz2') || str_ends_with($ext, '.tar.xz')) {
                exec("tar -xf " . escapeshellarg($file) . " -C " . escapeshellarg($dest));
            } elseif (str_ends_with($ext, '.zip')) {
                exec("unzip -q " . escapeshellarg($file) . " -d " . escapeshellarg($dest));
            }
        }
    }

    private function runShellSection(
        string $section,
        string $srcDir,
        string $pkgDir,
        array $meta,
        int $jobs
    ): void {
        if (trim($section) === '') return;

        // Find the first extracted directory as build base
        $dirs  = glob($srcDir . '/*/') ?: [$srcDir];
        $bDir  = realpath($dirs[0]) ?: $srcDir;

        $script = tempnam('/tmp', 'ko4build_');
        if ($script === false) {
            throw new BuildException("Failed to create temporary build script in /tmp — check permissions.");
        }

        $name = $meta['name'] ?? '';
        $ver  = $meta['version'] ?? '';

        $env = <<<BASH
#!/bin/bash
set -o pipefail

# Treat unset variables as errors but NOT set -e — many build systems
# (texinfo, gettext, etc.) return non-zero on sub-targets that are harmless.
# Each section should explicitly check what matters.

export name="{$name}"
export version="{$ver}"
export PKGDIR="{$pkgDir}"
export SRCDIR="{$srcDir}"
export BUILDDIR="{$bDir}"
export JOBS={$jobs}
export MAKEFLAGS="-j{$jobs}"

# Helper: die with a clear message
die() { echo "[ko4 build error] \$*" >&2; exit 1; }

# Ensure PKGDIR exists
mkdir -p "\$PKGDIR"

cd "{$bDir}" || die "Cannot cd to build directory: {$bDir}"

BASH;

        file_put_contents($script, $env . "\n" . $section);
        chmod($script, 0755);

        $timeout = $this->config->get('build_timeout', 3600);
        $ret     = null;

        try {
            passthru("timeout {$timeout} bash " . escapeshellarg($script) . " 2>&1", $ret);
        } finally {
            // Always clean up — is_string guards against false slipping past tempnam check
            if (is_string($script) && file_exists($script)) {
                unlink($script);
            }
        }

        if ($ret !== 0) {
            throw new BuildException("Build section failed with exit code $ret.");
        }
    }

    private function postProcess(string $pkgDir): void
    {
        // Strip binaries (reduces size significantly)
        if ($this->config->get('strip_binaries', true)) {
            exec("find " . escapeshellarg($pkgDir) . " -type f -executable 2>/dev/null | " .
                 "xargs file 2>/dev/null | grep -E 'ELF.*(executable|shared)' | cut -d: -f1 | " .
                 "xargs strip --strip-unneeded 2>/dev/null || true");
        }

        // Compress man pages
        if ($this->config->get('compress_man', true)) {
            exec("find " . escapeshellarg($pkgDir) . " -path '*/man/*' -type f ! -name '*.gz' " .
                 "2>/dev/null | xargs gzip -9 2>/dev/null || true");
        }

        // Remove libtool archives
        exec("find " . escapeshellarg($pkgDir) . " -name '*.la' -delete 2>/dev/null || true");
    }

    private function assertPkgDirNotEmpty(string $pkgDir, string $name): void
    {
        $count = 0;
        if (is_dir($pkgDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pkgDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                $base = $f->getFilename();
                if ($base === '.ko4meta' || $base === '.KO4BUILD') continue;
                if (!$f->isDir()) $count++;
            }
        }

        if ($count === 0) {
            $msg  = "Package directory is empty after [package] section for '{$name}'.\n";
            $msg .= "  DESTDIR was likely not honoured. Common fixes:\n";
            $msg .= '    1. make DESTDIR="$PKGDIR" install' . "\n";
            $msg .= '    2. make install prefix="$PKGDIR/usr"' . "\n";
            $msg .= "    3. Add 'set -x' to [package] to trace execution\n";
            $msg .= "    4. ko4 build --keep-build-dir {$name}  (then inspect \$PKGDIR)";
            throw new BuildException($msg);
        }

        Terminal::dim("  Packaged {$count} file(s) into staging directory.");
    }

    private function createArchive(string $pkgDir, string $pkgFile, array $meta, string $scriptPath): void
    {
        // Collect file list + checksums
        // getRealPath() returns false for symlinks — use getPathname() as the safe fallback
        $files = [];
        $iter  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pkgDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            $realPath = $f->getRealPath();
            $safePath = ($realPath !== false) ? $realPath : $f->getPathname();
            $rel      = substr($safePath, strlen($pkgDir));
            $type     = $f->isLink() ? 'symlink' : ($f->isDir() ? 'dir' : 'file');
            $files[] = [
                'path'     => $rel,
                'type'     => $type,
                'checksum' => ($type === 'file' && $realPath !== false) ? hash_file('sha256', $realPath) : null,
                'mode'     => ($realPath !== false) ? decoct(fileperms($realPath) & 0777) : '755',
            ];
        }

        // Build metadata
        $meta['files']      = $files;
        $meta['build_date'] = date('Y-m-d\TH:i:s\Z');
        $meta['packager']   = posix_getlogin() ?: 'ko4';

        // Create tar.xz of pkgDir + metadata JSON
        $metaFile = $pkgDir . '/.ko4meta';
        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));

        // Copy KO4BUILD into archive
        copy($scriptPath, $pkgDir . '/.KO4BUILD');

        $ret = null;
        exec("tar -cJf " . escapeshellarg($pkgFile) . " -C " . escapeshellarg($pkgDir) . " . 2>&1", $out, $ret);
        if ($ret !== 0) {
            throw new BuildException("Failed to create package archive: " . implode("\n", $out));
        }

        Terminal::dim("  Package: $pkgFile (" . Terminal::formatSize(filesize($pkgFile)) . ")");
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $path = $f->getRealPath();
            // getRealPath() returns false for broken symlinks — use getPathname() as fallback
            if ($path === false) {
                $path = $f->getPathname();
            }
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
