<?php
namespace WebRegulate\DevCompanion\Classes;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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