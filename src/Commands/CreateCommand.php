<?php
declare(strict_types=1);

namespace Ko4\Commands;

use Ko4\Core\Terminal;
use Ko4\Repository\RepoManager;

class CreateCommand extends AbstractCommand
{
    public function execute(array $args, array $flags): int
    {
        if (empty($args)) {
            Terminal::error("Usage: ko4 create <package-name>");
            return 1;
        }

        $name    = $args[0];
        $dir     = KO4_HOME . "/recipes/$name";
        $outFile = $dir . "/KO4BUILD";

        if (file_exists($outFile) && !isset($flags['force'])) {
            Terminal::error("KO4BUILD already exists at $outFile. Use --force to overwrite.");
            return 1;
        }

        @mkdir($dir, 0755, true);

        $version = Terminal::prompt("Version", "1.0.0");
        $desc    = Terminal::prompt("Description", "");
        $url     = Terminal::prompt("Homepage URL", "");
        $license = Terminal::prompt("License", "GPL-2.0");

        $template = $this->generateTemplate($name, $version, $desc, $url, $license);
        file_put_contents($outFile, $template);

        Terminal::success("Created: $outFile");
        Terminal::dim("  Edit the KO4BUILD file and run: ko4 build $name");
        return 0;
    }

    private function generateTemplate(
        string $name, string $version, string $desc, string $url, string $license
    ): string {
        return <<<KO4BUILD
[meta]
name        = {$name}
version     = {$version}
release     = 1
description = {$desc}
url         = {$url}
license     = {$license}
arch        = x86_64
# Comma-separated runtime dependencies
deps        = 
# Comma-separated build-time dependencies
makedeps    = gcc, make
# Optional: packages this provides (virtual packages)
# provides  = 
# Optional: packages this conflicts with
# conflicts = 

[sources]
# Format: <url> <algo>:<checksum>
# Variables: \${version} is replaced automatically
# https://example.com/downloads/{$name}-\${version}.tar.gz sha256:abc123...

[prepare]
#!/bin/bash
# Optional: run before build (e.g. patch, autoreconf)

[build]
#!/bin/bash
# Build the software
# Available variables:
#   \$SRCDIR  - extracted source directory
#   \$PKGDIR  - staging directory (install here, not /)
#   \$JOBS    - number of CPU cores
#   \$MAKEFLAGS - pre-set to -j\$JOBS

./configure \\
    --prefix=/usr \\
    --sysconfdir=/etc \\
    --localstatedir=/var

make \$MAKEFLAGS

[check]
#!/bin/bash
# Optional: run test suite (only if ko4.conf: run_tests = true)
# make check

[package]
#!/bin/bash
# Install files into \$PKGDIR (staging area, NOT the real system)
make DESTDIR="\$PKGDIR" install

# Install license
install -Dm644 COPYING "\$PKGDIR/usr/share/licenses/{$name}/LICENSE"

# Optional post-install script (runs on target system after install)
# install -Dm755 ../post-install.sh "\$PKGDIR/.hooks/post-install"

KO4BUILD;
    }
}

// ── Pack ──────────────────────────────────────────────────────────────────────
