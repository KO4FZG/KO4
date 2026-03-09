# ko4 — KO4OS Package Manager

```
  ██╗  ██╗ ██████╗ ██╗  ██╗
  ██║ ██╔╝██╔═══██╗██║  ██║
  █████╔╝ ██║   ██║███████║
  ██╔═██╗ ██║   ██║╚════██║
  ██║  ██╗╚██████╔╝     ██║
  ╚═╝  ╚═╝ ╚═════╝      ╚═╝
```

> A full-featured package manager for Linux From Scratch and custom Linux distributions.
> Written in PHP with SQLite as the database backend.

---

## Features

### Core Package Management
- **Install** packages from binary repos or build from source — your choice, per package
- **Remove** packages with optional cascade (remove dependents too)
- **Upgrade** all packages or specific ones; pinned packages are always skipped
- **Downgrade** to any previously cached version
- **Reinstall** to repair or reset a package to its packaged state
- **Autoremove** orphaned dependencies that are no longer needed

### Dependency Resolution
- Full **recursive dependency resolution** with topological sort (deepest deps install first)
- **Circular dependency detection** with a clear cycle trace in the error message
- **Build dependency** tracking (makedeps) separate from runtime deps
- **Optional dependency** support — install them when you want with `--optional`
- Resolves **virtual packages** via the `provides` field
- Marks deps as `explicit` or `dependency` so autoremove works correctly

### Source Builds with KO4BUILD Scripts
- **KO4BUILD** is a simple INI + Bash hybrid recipe format (inspired by Arch's PKGBUILD)
- Recipes have clearly defined sections: `[meta]`, `[sources]`, `[prepare]`, `[build]`, `[check]`, `[package]`
- **Checksum verification** of downloaded sources (sha256, sha512, md5)
- Automatic **parallel builds** with `$MAKEFLAGS` and `$JOBS` set from CPU count
- **Strip binaries**, **compress man pages**, and **remove .la files** automatically post-build
- **Build cache** — rebuild only when needed; `ko4 rebuild` forces a fresh build
- **Build timeout** protection (configurable, default 1 hour)
- Scaffold a new recipe instantly with `ko4 create <name>`

### Binary Packages
- Binary packages are distributed as `.ko4pkg` files (compressed tar.xz archives)
- Contains: all installed files + `.ko4meta` JSON manifest + `.KO4BUILD` source recipe
- **SHA-256 integrity verification** of every file at install time
- **Checksum-verified downloads** from repositories
- Optional **GPG signature verification** of repo indexes

### Repository System
- Multiple repos with configurable **priority** (lower number = higher priority)
- `ko4 sync` fetches the package index from all enabled repos
- Enable/disable repos without removing them
- Generate your own repo index from a directory of `.ko4pkg` files with `ko4 repo index`
- Repos can offer both binary and source packages simultaneously

### Safety & Conflict Management
- **Pin packages** to a version to prevent accidental upgrades
- **Conflict detection** — refuses to install packages that conflict with installed ones
- **Reverse dependency checking** before removal — warns if removing would break something
- **Config file protection** — files in `/etc` are backed up as `.luxorig` before overwrite
- **Integrity verification** with `ko4 verify` — detects missing or modified files
- Transaction **rollback** on failure (database-level, via SQLite transactions)

### Hooks
- **pre-install / post-install** hooks inside the package itself (`.hooks/` dir in `.ko4pkg`)
- **pre-remove / post-remove** hooks stored in `/var/lib/ko4/hooks/<name>/`
- Hooks are plain bash scripts — run ldconfig, update icon caches, create users, etc.

### Query & Introspection
- **Search** across all synced repos by name or description
- **Detailed info** for any package (installed or available)
- **List** all installed packages with optional verbose output; filter to explicit installs
- **File listing** — every file owned by a package
- **Owner lookup** — which package owns a given file on your system
- **Dependency tree** — visual tree of all transitive deps
- **Reverse deps** — what depends on a given package

### Maintenance
- **Transaction log** — full audit trail of every install, upgrade, remove
- **Security audit** — quickly see which installed packages have updates available
- **Cache management** — clean old package versions, keeping newest by default
- **Export/Import** — save your package list as JSON, restore on another system
- **Diff** — compare a saved package list against the current system state
- **Plugin system** — drop PHP files into `/var/lib/ko4/plugins/` to extend ko4

---

## Requirements

- **PHP 8.1+** with extensions: `pdo`, `pdo_sqlite`, `json`, `posix`, `pcre`
- **SQLite 3.x** (bundled with the PHP PDO SQLite extension)
- **bash** (for KO4BUILD scripts)
- **tar** with xz support (`tar -xJf`)
- **curl** or **wget** (for downloading sources and repo indexes)
- Optional: **gpg** (for package signature verification)
- Optional: **strip** from binutils (for binary stripping after source builds)

### Compiling PHP for LFS

When building PHP from source for your LFS system, make sure to include:

```bash
./configure \
    --prefix=/usr          \
    --sysconfdir=/etc      \
    --with-pdo-sqlite      \
    --with-sqlite3         \
    --enable-pdo           \
    --enable-posix         \
    --enable-json          \
    --disable-cgi          \
    # ... other options
```

---

## Installation

```bash
# Clone or copy the ko4 source to your system
cd /path/to/ko4os

# Run the installer as root
sudo bash install.sh
```

The installer will:
1. Verify PHP and required extensions are present
2. Create all needed directories under `/var/lib/ko4`, `/var/cache/ko4`
3. Install the `ko4` binary to `/usr/local/bin/ko4`
4. Install source files to `/usr/local/lib/ko4/`
5. Install default config to `/etc/ko4/ko4.conf`

### Manual Installation

If you prefer to install by hand:

```bash
# Copy files
install -Dm755 ko4 /usr/local/bin/ko4
cp -r src/ /usr/local/lib/ko4/src/

# Update the source path in the binary
sed -i "s|dirname(__FILE__)|'/usr/local/lib/ko4'|" /usr/local/bin/ko4

# Create runtime directories
mkdir -p /var/lib/ko4/{repos,recipes,plugins,hooks,installed}
mkdir -p /var/cache/ko4/packages
mkdir -p /etc/ko4

# Install config
install -Dm644 ko4.conf /etc/ko4/ko4.conf
```

### Environment Variables

All paths can be overridden with environment variables (useful for chroot or sysroot builds):

| Variable     | Default             | Description                          |
|-------------|---------------------|--------------------------------------|
| `KO4_HOME`   | `/var/lib/ko4`      | Database and runtime data directory  |
| `KO4_CACHE`  | `/var/cache/ko4`    | Downloaded package cache             |
| `KO4_CONFIG` | `/etc/ko4/ko4.conf` | Config file location                 |
| `KO4_LOG`    | `/var/log/ko4.log`  | Log file                             |
| `KO4_DEBUG`  | (unset)             | Set to `1` for verbose error traces  |

---

## Installing to a Different Root (Sysroot / New Disk)

The `--root=` flag lets you install packages into any directory or block device instead of the running system. This is designed for LFS install scripts, bootstrapping a new disk, or building a sysroot.

```bash
# Install into a directory
ko4 install --root=/mnt/newdisk bash coreutils

# Install directly to a block device — ko4 will mount it automatically
ko4 install --root=/dev/sda1 bash coreutils glibc

# Install your whole base system in one go
ko4 import base-system.json --root=/dev/sda1 -y
```

**How it works:**

- If `--root=` is a **directory**, files are deployed there directly (e.g. `/mnt/newdisk/usr/bin/bash`)
- If `--root=` is a **block device** (e.g. `/dev/sda1`), ko4 checks `/proc/mounts` — if it's already mounted it uses that mount point, otherwise it mounts it automatically at `/mnt/ko4-target-sda1` and reminds you to unmount when done
- Each target root gets its own **separate SQLite database** stored at `/var/lib/ko4/targets/dev_sda1.db`, so the package state for your new install is tracked independently from your host system
- The host's `/var/lib/ko4` and package cache are always used regardless of `--root=`, so you don't re-download packages

**Typical LFS install script pattern:**

```bash
#!/bin/bash
# Partition and format
mkfs.ext4 /dev/sda2
mkswap /dev/sda3

# Bootstrap base system packages into the new root
ko4 install --root=/dev/sda2 -y \
    linux-headers glibc glibc-devel \
    bash coreutils binutils gcc make \
    util-linux e2fsprogs

# Install bootloader, kernel, etc.
ko4 install --root=/dev/sda2 -y grub linux

# Check what's installed on the target
KO4_HOME=/var/lib/ko4 ko4 list   # (uses targets/dev_sda2.db automatically with --root)
```

---

## Usage

### Getting Started

```bash
# Add a repository
ko4 repo add main https://packages.yourdistro.example.com

# Sync the package index
ko4 sync

# Search for a package
ko4 search vim

# Install a package (binary)
ko4 install vim curl bash

# Install from source
ko4 install --source vim

# Show info about a package
ko4 info curl
```

### Installing Packages

```bash
# Install one or more packages
ko4 install vim git curl

# Install from source (builds using KO4BUILD recipe)
ko4 install --source vim

# Install and mark as a dependency (won't block autoremove)
ko4 install --asdep zlib

# Skip confirmation prompts
ko4 install -y vim git

# Reinstall a broken or modified package
ko4 reinstall curl
```

### Removing Packages

```bash
# Remove a package
ko4 remove vim

# Remove and also remove packages that depend on it
ko4 remove --cascade vim

# Remove without deleting files (just unregister from DB)
ko4 remove --keep-files vim

# Remove orphaned dependency packages
ko4 autoremove
```

### Upgrading

```bash
# Upgrade all installed packages
ko4 upgrade

# Upgrade specific packages only
ko4 upgrade curl vim

# Downgrade to a previously cached version
ko4 downgrade curl 8.6.0
```

### Building from Source

```bash
# Build a package using its KO4BUILD recipe
ko4 build curl

# Force a rebuild (ignore build cache)
ko4 rebuild curl

# Build without installing (create .ko4pkg only)
ko4 build --no-install curl

# Build targeting a specific version
ko4 build --version=8.6.0 curl

# Create a new KO4BUILD recipe scaffold
ko4 create mypackage

# Build and pack from the current directory (must have a KO4BUILD file)
ko4 pack
```

### Querying

```bash
# Search repos
ko4 search sqlite

# Detailed package info
ko4 info openssl

# List all installed packages
ko4 list

# List only explicitly installed packages (not pulled in as deps)
ko4 list --explicit

# List with version, arch, size details
ko4 list --verbose

# See every file a package installed
ko4 files curl

# Which package owns a file?
ko4 owns /usr/bin/curl
ko4 owns /etc/ssl/openssl.cnf

# Show full dependency tree
ko4 deps vim

# What depends on openssl?
ko4 rdeps openssl
```

### Repositories

```bash
# List configured repos
ko4 repo list

# Add a repo (priority: lower = higher priority)
ko4 repo add main   https://repo.example.com       50
ko4 repo add local  https://mybuilds.example.com   10

# Add a repo with GPG verification
ko4 repo add main https://repo.example.com --gpg-key=KEYID

# Remove a repo
ko4 repo remove local

# Temporarily disable a repo
ko4 repo disable testing
ko4 repo enable  testing

# Sync only one repo
ko4 sync main

# Generate an index.json from a directory of .ko4pkg files
# (for hosting your own repo)
ko4 repo index /srv/repo/packages /srv/repo/index.json
```

### Maintenance

```bash
# Verify integrity of all installed files
ko4 verify

# Verify specific packages
ko4 verify curl openssl

# Check for available updates
ko4 audit

# View transaction history
ko4 log

# View history for a specific package
ko4 log curl

# Show last 50 transactions
ko4 log --limit=50

# Clean old cached packages (keep newest version per package)
ko4 clean

# Remove ALL cached packages
ko4 clean --all

# Pin a package (prevents upgrade)
ko4 pin curl
ko4 pin curl --version=8.6.0 --reason="API compatibility"

# Unpin
ko4 unpin curl

# List pinned packages
ko4 pin

# Export installed package list
ko4 export packages.json

# Re-install packages from an export file (on a new system)
ko4 import packages.json

# Diff current system against a saved export
ko4 diff packages.json
```

---

## KO4BUILD Reference

A `KO4BUILD` file defines how to build a package from source. It is a plain text file with INI-style section headers and bash code blocks.

### Full Example

```
[meta]
name        = example
version     = 1.2.3
release     = 1
description = An example package
url         = https://example.com
license     = GPL-2.0
arch        = x86_64
deps        = openssl, zlib
makedeps    = cmake, perl
optdeps     = lua: Lua scripting support
provides    = libexample
conflicts   = example-legacy

[sources]
https://example.com/downloads/example-${version}.tar.gz sha256:abc123...
https://example.com/downloads/example-${version}.tar.gz.patch

[prepare]
#!/bin/bash
patch -p1 < "$SRCDIR/example-${version}.tar.gz.patch"

[build]
#!/bin/bash
./configure \
    --prefix=/usr       \
    --sysconfdir=/etc   \
    --disable-static

make $MAKEFLAGS

[check]
#!/bin/bash
make check

[package]
#!/bin/bash
make DESTDIR="$PKGDIR" install
install -Dm644 LICENSE "$PKGDIR/usr/share/licenses/$name/LICENSE"
```

### Available Variables in Shell Sections

| Variable      | Description                                              |
|--------------|----------------------------------------------------------|
| `$name`       | Package name from `[meta]`                               |
| `$version`    | Package version from `[meta]`                            |
| `$SRCDIR`     | Directory where source archives were downloaded/extracted |
| `$BUILDDIR`   | The first extracted source directory (where you `cd` to) |
| `$PKGDIR`     | Staging directory — install here, **not** into `/`       |
| `$JOBS`       | Number of CPU cores (from config or `nproc`)             |
| `$MAKEFLAGS`  | Pre-set to `-j$JOBS` for convenience                    |

### Section Reference

| Section     | Required | Description                                             |
|-------------|----------|---------------------------------------------------------|
| `[meta]`    | Yes      | Package metadata (name, version, deps, etc.)            |
| `[sources]` | No       | Source URLs with optional checksums                     |
| `[prepare]` | No       | Runs before build (patching, autoreconf, etc.)          |
| `[build]`   | No       | Compiles the software                                   |
| `[check]`   | No       | Runs tests (only if `run_tests = true` in config)       |
| `[package]` | Yes      | Installs into `$PKGDIR` (creates the actual package)    |

### Hooks

Install-time hooks are bash scripts placed inside `$PKGDIR/.hooks/`:

```bash
# In [package] section:
install -d "$PKGDIR/.hooks"
cat > "$PKGDIR/.hooks/post-install" << 'HOOK'
#!/bin/bash
# Runs on the target system after the package files are placed
ldconfig
systemctl daemon-reload 2>/dev/null || true
HOOK
chmod +x "$PKGDIR/.hooks/post-install"
```

Available hook names: `pre-install`, `post-install`, `pre-remove`, `post-remove`

---

## Hosting a Repository

To host your own package repository:

1. Build your packages: `ko4 build mypackage`
2. Collect the `.ko4pkg` files into a directory, e.g. `/srv/repo/packages/`
3. Generate an index: `ko4 repo index /srv/repo/packages /srv/repo/index.json`
4. Serve the directory over HTTP (nginx, lighttpd, etc.)
5. Users add your repo: `ko4 repo add myrepo http://your-server/repo`
6. Users sync: `ko4 sync`

The `index.json` format is a simple JSON file with a `packages` array. Regenerate it whenever you add new packages. For GPG signing, sign the `index.json` and produce `index.json.sig`.

---

## Configuration Reference (`/etc/ko4/ko4.conf`)

| Key               | Default           | Description                                         |
|------------------|-------------------|-----------------------------------------------------|
| `confirm`         | `true`            | Ask before making changes                           |
| `color`           | `true`            | Colored terminal output                             |
| `progress`        | `true`            | Show progress bars                                  |
| `install_root`    | `/`               | Root for file installation (useful for chroot work) |
| `default_arch`    | `x86_64`          | Target architecture                                 |
| `keep_cache`      | `true`            | Keep downloaded `.ko4pkg` files                     |
| `build_dir`       | `/tmp/ko4-build`  | Temporary directory for source builds               |
| `source_dir`      | `/usr/src/ko4`    | Where to keep source archives                       |
| `jobs`            | `auto`            | Build parallelism (auto = `nproc`)                  |
| `strip_binaries`  | `true`            | Strip debug symbols from compiled binaries          |
| `compress_man`    | `true`            | Gzip man pages after install                        |
| `run_tests`       | `false`           | Run `[check]` section during builds                 |
| `keep_build_dir`  | `false`           | Keep build directory (useful for debugging)         |
| `build_timeout`   | `3600`            | Max seconds for a build before timeout              |
| `parallel_downloads` | `3`           | Simultaneous downloads                              |
| `verify_gpg`      | `false`           | Verify GPG signatures on repo indexes               |
| `log_level`       | `info`            | Logging verbosity                                   |
| `max_log_size`    | `10M`             | Log rotation threshold                              |

---

## File Layout

```
/usr/local/bin/ko4            ← main executable
/usr/local/lib/ko4/
  src/
    Application.php           ← command router
    Ko4Exception.php          ← exception hierarchy
    Commands/
      AllCommands.php         ← search, info, list, files, owns, deps, sync, repo, ...
      InstallCommand.php      ← install, reinstall
      RemoveCommand.php       ← remove
      UpgradeCommand.php      ← upgrade, downgrade
      BuildCommand.php        ← build, rebuild
    Core/
      Config.php              ← config file loader
      Database.php            ← SQLite + migrations
      Logger.php              ← rotating log writer
      Terminal.php            ← colors, tables, progress bars, prompts
    Package/
      Package.php             ← package model + version comparison
      PackageRegistry.php     ← all DB operations for packages
      DependencyResolver.php  ← dep resolution + topological sort
      Installer.php           ← file deployment + removal
    Build/
      Builder.php             ← KO4BUILD parser + build runner
    Repository/
      RepoManager.php         ← repo add/remove/sync + index generation

/etc/ko4/ko4.conf             ← user configuration
/var/lib/ko4/
  ko4.db                      ← SQLite database
  recipes/                    ← KO4BUILD recipes (one dir per package)
  hooks/                      ← system-level post-remove/pre-remove hooks
  plugins/                    ← PHP plugin files
/var/cache/ko4/
  packages/                   ← cached .ko4pkg binary archives
/var/log/ko4.log              ← transaction and event log
```

---

## License

MIT License. Do whatever you want with it — it's your distro.
