# KO4OS — Claude Context

This is **ko4**, the package manager for the KO4OS Linux From Scratch distribution.

## Project Overview

`ko4` is a CLI package manager written in **PHP 8.1+** with **SQLite** (via PDO) as the
database backend. It manages packages on a custom Linux distribution built with Linux From Scratch.

## Architecture

```
ko4                          ← CLI entry point (PHP shebang)
src/
  Application.php            ← Command router, bootstrapper, plugin loader
  Ko4Exception.php           ← Exception hierarchy
  Commands/
    AbstractCommand.php      ← Base class (injects Config, Database, Logger, Registry, Resolver)
    AllCommands.php          ← Search, Info, List, Files, Owns, Deps, Sync, Repo, Log,
                                Clean, Autoremove, Pin, Verify, Audit, Export, Import,
                                Diff, Version, Create, Pack, Help
    InstallCommand.php       ← install, reinstall
    RemoveCommand.php        ← remove (with --cascade, --keep-files)
    UpgradeCommand.php       ← upgrade + DowngradeCommand
    BuildCommand.php         ← build, rebuild (delegates to Builder)
  Core/
    Config.php               ← INI-style config loader (/etc/ko4/ko4.conf)
    Database.php             ← PDO SQLite wrapper with versioned migrations
    Logger.php               ← Rotating file logger
    Terminal.php             ← Colors, progress bars, tables, prompts, spinners
  Package/
    Package.php              ← Package model + version comparator
    PackageRegistry.php      ← All SQLite CRUD for packages, deps, files, pins, log
    DependencyResolver.php   ← Recursive resolver with cycle detection + topological sort
    Installer.php            ← File deployment to disk, removal, hook execution
  Build/
    Builder.php              ← KO4BUILD parser, source downloader, build runner, .ko4pkg creator
  Repository/
    RepoManager.php          ← Repo add/remove/sync/enable/disable + index generation
```

## Key Concepts

### Package Format
- Binary packages: `.ko4pkg` (tar.xz archive)
- Contains files + `.ko4meta` (JSON manifest) + `.KO4BUILD` (source recipe)
- Integrity verified with SHA-256 checksums per file

### KO4BUILD Scripts
Recipe format with INI sections (`[meta]`, `[sources]`, `[prepare]`, `[build]`, `[check]`, `[package]`)
and bash code blocks. Variables: `$SRCDIR`, `$PKGDIR`, `$BUILDDIR`, `$JOBS`, `$MAKEFLAGS`.
Recipes live in `/var/lib/ko4/recipes/<name>/KO4BUILD`.

### Database Schema (SQLite)
- `packages` — installed package registry
- `repo_packages` — packages available in synced repos
- `dependencies` — dep relationships (required / optional / makedep)
- `files` — every file owned by every package (enables `ko4 owns <file>`)
- `repos` — configured repositories with priority
- `transaction_log` — full audit trail
- `pinned` — packages locked from upgrade
- `config` — key/value store
- `schema_version` — migration tracking

### Namespaces
All PHP classes use the `Ko4\` namespace. Autoloader maps `Ko4\Foo\Bar` → `src/Foo/Bar.php`.

### Constants (defined in `ko4` entry point)
`KO4_VERSION`, `KO4_ROOT`, `KO4_HOME` (`/var/lib/ko4`), `KO4_CACHE` (`/var/cache/ko4`),
`KO4_CONFIG` (`/etc/ko4/ko4.conf`), `KO4_LOG` (`/var/log/ko4.log`), `KO4_DB`, `KO4_REPOS`,
`KO4_PKGDB`, `KO4_HOOKS`

## Coding Conventions

- PHP 8.1+ features: constructor promotion, `match`, `str_starts_with`, enums where appropriate
- `declare(strict_types=1)` in every file
- No Composer — zero external dependencies by design (runs on a minimal LFS system)
- All DB operations must go through `PackageRegistry` — commands never query the DB directly
- File operations that touch the real filesystem live in `Installer`, not commands
- Shell commands in `Builder` use `escapeshellarg()` — never interpolate user input directly
- New commands: extend `AbstractCommand`, add to `Application::COMMANDS` map
- New DB columns: add a new migration entry in `Database::getMigrations()`

## Testing

There is currently no test suite. When adding tests, prefer PHPUnit placed in `tests/`.
Test against a temporary SQLite DB (`:memory:` or a tmpfile), not the real system DB.

## Common Tasks for Claude

- **Add a new command**: create `src/Commands/MyCommand.php` extending `AbstractCommand`,
  add `'mycommand' => Commands\MyCommand::class` to `Application::COMMANDS`
- **Add a DB table/column**: add a new integer key in `getMigrations()` with the ALTER/CREATE SQL
- **Add a KO4BUILD feature**: modify `Builder::parseScript()` and the relevant `run*` method
- **Fix a dep resolution bug**: look at `DependencyResolver::visit()` and `buildInstallPlan()`
- **Add a repo feature**: extend `RepoManager`, add a sub-command case in `RepoCommand::execute()`
