<?php
namespace WebRegulate\DevCompanion\Classes;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use function Laravel\Prompts\select;
use WebRegulate\DevCompanion\DevCompanion;

class BaseCommand extends Command
{
    public function displayCommands(bool $bool = true): static
    {
        DevCompanion::$displayCommands = $bool;
        
        return $this;
    }

    public function localCommand(string|array $commands): static
    {
        if (is_string($commands)) {
            $commands = explode(PHP_EOL, $commands);
        }

        $this->call('dev-companion:local', [
            'commands' => $commands,
        ]);

        return $this;
    }

    public function sshCommand(?string $connectionKey, array $commands = []): static
    {
        $this->call('dev-companion:ssh', [
            'connection_key' => $connectionKey,
            'commands' => $commands,
        ]);

        return $this;
    }

    public function download(string $connectionKey, string $remotePath, string $localPath, bool $recursive = false, bool $replace = false): static
    {
        $localPath = $this->absoluteLocalPath($localPath);
        $destinationExists = file_exists($localPath);

        if ($destinationExists && ! $replace) {
            throw new RuntimeException('The download destination already exists; use replace: true to overwrite.');
        }

        File::ensureDirectoryExists(dirname($localPath));

        $temporaryPath = null;
        try {
            if ($recursive) {
                $remote = $this->transfer($connectionKey, $remotePath, $localPath, true, true);
            } else {
                $temporaryPath = tempnam(dirname($localPath), '.dev-companion-');

                if ($temporaryPath === false) {
                    throw new RuntimeException('Unable to create a temporary download file.');
                }

                $remote = $this->transfer($connectionKey, $remotePath, $temporaryPath, true, false, $localPath);

                if (! rename($temporaryPath, $localPath)) {
                    throw new RuntimeException("Unable to replace the local file: {$localPath}");
                }
            }
        } catch (Throwable $exception) {
            $this->error('Download failed: '.OutputFormatter::escape($exception->getMessage()));
            $this->warn($recursive ? 'The destination may contain partially downloaded files.' : 'The local destination was not replaced.');

            throw $exception;
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }

        $type = $recursive ? 'folder recursively' : 'file';
        $this->reportTransfer("Downloaded {$type}", $remote, $localPath, true);
        $this->info('Download complete.'.($destinationExists && ! $recursive ? ' Existing local file replaced.' : ''));
        $this->newLine();

        return $this;
    }

    public function upload(string $connectionKey, string $localPath, string $remotePath, bool $recursive = false): static
    {
        $localPath = $this->absoluteLocalPath($localPath);

        if (! file_exists($localPath) || (is_dir($localPath) && ! $recursive)) {
            throw new InvalidArgumentException('The upload source must exist; directories require recursive: true.');
        }

        try {
            $remote = $this->transfer($connectionKey, $remotePath, $localPath, false, $recursive);
        } catch (Throwable $exception) {
            $this->error('Upload failed: '.OutputFormatter::escape($exception->getMessage()));
            $this->warn('The remote destination may contain partially uploaded files.');

            throw $exception;
        }

        $type = $recursive ? 'folder recursively' : 'file';
        $this->reportTransfer("Uploaded {$type}", $localPath, $remote, false);
        $this->info('Upload complete.');
        $this->newLine();

        return $this;
    }

    protected function absoluteLocalPath(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('A local path is required.');
        }

        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : base_path($path);
    }

    protected function reportTransfer(string $action, string $source, string $destination, bool $download): void
    {
        $source = OutputFormatter::escape($source);
        $destination = OutputFormatter::escape($destination);
        $sourceType = $download ? 'remote' : 'local';
        $destinationType = $download ? 'local' : 'remote';
        $this->line("<fg=white>{$action} from {$sourceType}: </><fg=yellow>{$source}</>\n<fg=white>to {$destinationType}: </><fg=yellow>{$destination}</>");
    }

    protected function transfer(string $connectionKey, string $remotePath, string $localPath, bool $download, bool $recursive, ?string $displayLocalPath = null): string
    {
        $connection = DevCompanion::setCurrentSshConnection($connectionKey);
        $host = $connection['host'] ?? '';
        $user = $connection['user'] ?? '';
        $port = filter_var($connection['port'] ?? 22, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-:\[\]]*$/', $host) || ! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $user) || $port === false) {
            throw new InvalidArgumentException('The SSH connection requires a valid host, user, and port.');
        }

        $remotePath = str_replace('{base_path}', $connection['base_path'] ?? '', $remotePath);

        if ($remotePath === '' || str_contains($remotePath, "\0") || str_contains($remotePath, "\n")) {
            throw new InvalidArgumentException('A valid remote path is required.');
        }

        if (! str_starts_with($remotePath, '/')) {
            if (empty($connection['base_path'])) {
                throw new InvalidArgumentException('Relative remote paths require a connection base_path.');
            }

            $remotePath = rtrim($connection['base_path'], '/').'/'.$remotePath;
        }

        $arguments = ['scp', '-s', '-B', '-P', (string) $port];

        if (! empty($connection['identity_file'])) {
            array_push($arguments, '-i', $connection['identity_file']);
        }

        if ($recursive) {
            $arguments[] = '-r';
        }

        $remote = "{$user}@{$host}:{$remotePath}";
        array_push($arguments, '--', ...($download ? [$remote, $localPath] : [$localPath, $remote]));

        $displayLocalPath ??= $localPath;
        $action = $download ? 'Downloading' : 'Uploading';
        $endpoint = $download ? 'from remote' : 'to remote';
        $type = $recursive ? 'folder recursively' : 'file';
        $connectionLabel = OutputFormatter::escape($connectionKey);
        $remoteLabel = OutputFormatter::escape($remote);

        $this->newLine();
        $this->line("<fg=white>{$action} {$type} {$endpoint}: </><fg=yellow>{$remoteLabel}</>");
        $this->line("<fg=white>Connection: </><fg=green>{$connectionLabel}</> <fg=white>(port {$port})</>");

        if (! $download) {
            $this->warn('Existing remote files with matching names may be overwritten.');
        } elseif (file_exists($displayLocalPath)) {
            $notice = $recursive ? 'Folders will be merged; existing local files with matching names may be overwritten.' : 'The existing local file will be replaced only after the download succeeds.';
            $this->line("<fg=white>{$notice}</>");
        }

        $this->newLine();
        Process::path(base_path())->forever()->run($arguments)->throw();

        return $remote;
    }

    /**
     * Conditionally execute a callback based on a boolean or callable condition.
     * @param callable $callback Passes only the current command instance
     */
    public function if(bool|callable $condition, callable $callback): static
    {
        if (is_callable($condition)) {
            $condition = call_user_func($condition, $this);
        }

        if ($condition) {
            call_user_func($callback, $this);
        }

        return $this;
    }

    public function then(callable $callback): static
    {
        call_user_func($callback, $this);

        return $this;
    }

    public function registeredCommand(string $commandKey, array $arguments): static
    {
        $this->newLine();
        $this->line("<info>Registered Command:</info>   <comment>{$commandKey}</comment>");

        $this->call(DevCompanion::$registeredCommands[$commandKey], $arguments);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description ?? 'Unnamed Command';
    }

    public function getRegisteredCommands(): array
    {
        return DevCompanion::$registeredCommands;
    }

    public function getConfig(?string $key = null): mixed
    {
        return DevCompanion::getConfig($key);
    }

    public function getSshConnectionKeys(): array
    {
        return array_keys(DevCompanion::getSshConnections());
    }

    public function getBranchToSshMappings(): array
    {
        $sshConnections = DevCompanion::getSshConnections();
        
        // return collect($sshConnections)->pluck('branch')->toArray();
        return collect($sshConnections)
            ->mapWithKeys(function ($connection, $key) {
                if(isset($connection['branch'])) {
                    return [$connection['branch'] => $key];
                }
            })
            ->toArray();
    }

    /**
     * Select a server key if multiple servers are configured, otherwise return the single configured server.
     */
    public function selectServerKey(?string $label = 'Select a server')
    {
        $connectionKeys = DevCompanion::getSshConnectionKeys();

        if (count($connectionKeys) === 1) {
            return array_key_first($connectionKeys);
        }
        
        return $this->select($label, DevCompanion::getSshConnectionKeys());
    }

    /**
     * Get current Git branch name
     */
    public function getCurrentGitBranch(): string
    {
        $branchName = trim(shell_exec('git branch --show-current'));
        return $branchName;
    }

    /**
     * Is git state clean
     */
    public function isGitStateClean(): bool
    {
        $gitStatus = trim(shell_exec('git status --porcelain'));
        return empty($gitStatus);
    }

    /**
     * Wrapper for Laravel Prompts select function.
     *
     * @param  array<int|string, string>|Collection<int|string, string>  $options
     * @param  true|string  $required
     */
    public function select(string $label, array|Collection $options, int|string|null $default = null, int $scroll = 5, mixed $validate = null, string $hint = '', bool|string $required = true, ?Closure $transform = null): int|string
    {
        return select($label, $options, $default, $scroll, $validate, $hint, $required, $transform);
    }
}