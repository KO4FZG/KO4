<?php
declare(strict_types=1);

namespace Ko4;

use Ko4\Core\Config;
use Ko4\Core\Database;
use Ko4\Core\Logger;
use Ko4\Core\Terminal;

class Application
{
    private array $argv;
    private Config $config;
    private Database $db;
    private Logger $logger;

    private const COMMANDS = [
        'install'   => Commands\InstallCommand::class,
        'remove'    => Commands\RemoveCommand::class,
        'upgrade'   => Commands\UpgradeCommand::class,
        'downgrade' => Commands\DowngradeCommand::class,
        'build'     => Commands\BuildCommand::class,
        'rebuild'   => Commands\BuildCommand::class,
        'reinstall' => Commands\InstallCommand::class,
        'search'    => Commands\SearchCommand::class,
        'info'      => Commands\InfoCommand::class,
        'list'      => Commands\ListCommand::class,
        'files'     => Commands\FilesCommand::class,
        'owns'      => Commands\OwnsCommand::class,
        'deps'      => Commands\DepsCommand::class,
        'rdeps'     => Commands\DepsCommand::class,
        'sync'      => Commands\SyncCommand::class,
        'repo'      => Commands\RepoCommand::class,
        'create'    => Commands\CreateCommand::class,
        'pack'      => Commands\PackCommand::class,
        'verify'    => Commands\VerifyCommand::class,
        'audit'     => Commands\AuditCommand::class,
        'pin'       => Commands\PinCommand::class,
        'unpin'     => Commands\PinCommand::class,
        'log'       => Commands\LogCommand::class,
        'clean'     => Commands\CleanCommand::class,
        'autoremove'=> Commands\AutoremoveCommand::class,
        'export'    => Commands\ExportCommand::class,
        'import'    => Commands\ImportCommand::class,
        'diff'      => Commands\DiffCommand::class,
        'version'   => Commands\VersionCommand::class,
        'help'      => Commands\HelpCommand::class,
    ];

    public function __construct(array $argv)
    {
        $this->argv = $argv;
    }

    public function run(): int
    {
        $this->bootstrap();

        $command = $this->argv[1] ?? 'help';

        if (in_array($command, ['-v', '--version'])) {
            $command = 'version';
        }
        if (in_array($command, ['-h', '--help'])) {
            $command = 'help';
        }

        if (!isset(self::COMMANDS[$command])) {
            Terminal::error("Unknown command: '{$command}'. Run 'ko4 help' for usage.");
            return 1;
        }

        $class = self::COMMANDS[$command];
        $args  = array_slice($this->argv, 2);
        $opts  = $this->parseOptions($args);

        /** @var Commands\AbstractCommand $cmd */
        $cmd = new $class($this->config, $this->db, $this->logger);
        $cmd->setCommandName($command);
        return $cmd->execute($opts['args'], $opts['flags']);
    }

    private function bootstrap(): void
    {
        // Ensure required directories exist
        foreach ([KO4_HOME, KO4_CACHE, KO4_REPOS, KO4_PKGDB, KO4_HOOKS] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        $this->config = new Config(KO4_CONFIG);
        $this->db     = new Database(KO4_DB);
        $this->logger = new Logger(KO4_LOG);

        // Run DB migrations
        $this->db->migrate();

        // Load plugins
        $this->loadPlugins();
    }

    private function parseOptions(array $args): array
    {
        $flags = [];
        $positional = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $key = ltrim($arg, '-');
                if (str_contains($key, '=')) {
                    [$k, $v] = explode('=', $key, 2);
                    $flags[$k] = $v;
                } else {
                    // Check if next arg is a value
                    $next = $args[$i + 1] ?? null;
                    if ($next && !str_starts_with($next, '-')) {
                        $flags[$key] = $next;
                        $i++;
                    } else {
                        $flags[$key] = true;
                    }
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) > 1) {
                // Short flags: -y, -s, etc.
                $chars = str_split(ltrim($arg, '-'));
                foreach ($chars as $c) {
                    $flags[$c] = true;
                }
            } else {
                $positional[] = $arg;
            }
        }

        return ['args' => $positional, 'flags' => $flags];
    }

    private function loadPlugins(): void
    {
        $pluginDir = KO4_HOME . '/plugins';
        if (!is_dir($pluginDir)) return;

        foreach (glob($pluginDir . '/*.php') as $plugin) {
            require_once $plugin;
        }
    }

    public static function getCommands(): array
    {
        return self::COMMANDS;
    }
}
