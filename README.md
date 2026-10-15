# Dev Companion

A local-only Artisan menu for Laravel development tasks: run shell commands, deploy branches, open SSH sessions, and transfer files.

## Features

| Feature | What it does |
| --- | --- |
| Interactive menu | Lists configured tasks; returns to the menu after each task. |
| Custom workflows | Register inline callbacks or reusable command classes. |
| Local commands | Run one command or a sequence with live terminal output. |
| SSH commands | Select a connection, run remote commands, or open an interactive shell. |
| Multiple environments | Configure named servers, startup commands, and branch mappings. |
| File and folder transfers | Upload or download via SCP/SFTP, including recursive folders. |
| Staged Git uploads | Preview staged file paths and confirm before uploading their local contents. |
| Workflow helpers | Chain steps, add conditions, prompt for choices, and inspect Git state. |

## Installation

**Active development. Not yet stable.** Requires PHP 8.2+ and Laravel 10-13.

```bash
composer require webregulate/dev-companion "^0.8.1"
```

Publish the configuration:

```bash
php artisan dev-companion:install
```

Edit `config/dev-companion.php` to configure tasks and SSH connections. Commands are registered only in the local console environment (`APP_ENV=local`).

SSH tasks require a local `ssh` client; transfers require `scp` with SFTP support. Git tasks also require Git. Transfer helpers use batch authentication, so configure SSH keys and trusted hosts first.

## Usage

```bash
php artisan dev-companion
```

Choose a task, follow its prompts, or select **Exit**.

### Included Menu Tasks

These examples are published with the configuration. Edit or remove them to suit your project.

| Key | Action |
| --- | --- |
| `deploy_example` | Deploy a mapped branch; merges `development` into `main` when deploying `main`. |
| `branch` | Deploy a chosen branch to a chosen server. |
| `composer` | Run remote `composer update --no-dev --optimize-autoloader`. |
| `npm` | Run remote `npm update`. |
| `versions` | Show local PHP, Composer, npm, and Node versions. |
| `git` | Upload files listed in the Git staging area after confirmation. |
| `ssh` | Open a configured SSH connection. |

**Deployment warning:** the deployment examples run `git reset --hard` remotely, install dependencies, and build assets. Check the target server and branch before running them. Staged uploads copy working-tree files, not staged snapshots, and do not synchronise deletions.

### Direct Commands

| Command | Action |
| --- | --- |
| `php artisan dev-companion` | Open the task menu. |
| `php artisan dev-companion:install` | Publish configuration. |
| `php artisan dev-companion:ssh` | Choose an SSH connection. |
| `php artisan dev-companion:ssh development` | Open a named connection. |
| `php artisan dev-companion:local "php -v" "node -v"` | Run local commands in order. |

## SSH Connections

Add connections under `ssh_connections` in `config/dev-companion.php`:

```php
'ssh_connections' => [
	'development' => [
		'branch' => 'development',
		'host' => 'dev.example.com',
		'port' => 22,
		'user' => 'forge',
		'base_path' => '/home/forge/dev.example.com',
		'commands' => 'cd {base_path}',
	],
],
```

| Setting | Purpose |
| --- | --- |
| `host`, `port`, `user` | SSH endpoint and account. Set all three for shell commands. |
| `base_path` | Remote project directory; resolves relative transfer paths. |
| `branch` | Optional branch-to-connection mapping for workflows. |
| `commands` | Optional startup command or array; supports placeholders such as `{base_path}`. |
| `identity_file` | Optional local private-key path for transfer helpers only. |

For interactive SSH and staged Git uploads, configure authentication through your SSH agent or SSH client configuration.

## Custom Tasks

Add an `InlineCommand` to the configuration's `commands` array:

```php
use WebRegulate\DevCompanion\Classes\InlineCommand;

'commands' => [
	'refresh' => InlineCommand::make('Refresh development', function (InlineCommand $command) {
		$connection = $command->select('Server', $command->getSshConnectionKeys());

		$command
			->localCommand('npm run build')
			->sshCommand($connection, ['php artisan optimize:clear', 'exit']);
	}),
],
```

For reusable tasks, extend `WebRegulate\DevCompanion\Classes\BaseCommand`, define a description and `handle()` method, then register the class as `'task' => YourCommand::class`.

### Helper Reference

Available to inline tasks and classes extending `BaseCommand`:

| Helper | Purpose |
| --- | --- |
| `localCommand($commands)` | Run a string or array of local commands. |
| `sshCommand($connectionKey, $commands)` | Run remote commands; use `exit` to disconnect automatically. |
| `download($connectionKey, $remotePath, $localPath, recursive: false, replace: false)` | Download a file or folder. |
| `upload($connectionKey, $localPath, $remotePath, recursive: false)` | Upload a file or folder. |
| `if($condition, $callback)` | Run a step when a boolean or callback is true. |
| `then($callback)` | Run a callback with the current command. |
| `registeredCommand($commandKey, $arguments)` | Call another registered menu task. |
| `displayCommands(false)` | Hide local command text; command output remains visible. |
| `select($label, $options)` | Prompt for a choice. |
| `getSshConnectionKeys()` | List configured connection names. |
| `selectServerKey($label)` | Server-selection helper. Prefer `select()` with `getSshConnectionKeys()` when a connection name is required. |
| `getBranchToSshMappings()` | Get branch names mapped to connection names. |
| `getCurrentGitBranch()` | Get the current local branch. |
| `isGitStateClean()` | Check for an empty Git status. |
| `getConfig($key)` | Read package configuration; omit the key for all settings. |
| `getRegisteredCommands()` | Get registered menu command instances. |
| `getDescription()` | Get the task description. |

Chaining sequences steps; it does not guarantee fail-fast execution. A failed local command stops its current list, but later chained steps can still run.

## File Transfers

Use inside a task callback:

```php
$command->download('development', 'storage/app/report.csv', 'storage/app/report.csv');

$command->download(
	'development',
	'storage/app/exports',
	'storage/app/exports',
	recursive: true,
	replace: true,
);

$command->upload('development', 'public/build', 'public/build', recursive: true);
```

| Behaviour | Rule |
| --- | --- |
| Relative paths | Local paths resolve from the Laravel project root; remote paths from the connection's `base_path`. |
| Remote placeholder | `{base_path}` is supported in transfer paths. |
| Existing download target | Rejected unless `replace: true`. |
| Single-file downloads | Replace the target only after the transfer succeeds. |
| Folder downloads | Require `recursive: true`; existing folders merge when replacement is allowed. |
| Uploads | Existing remote files may be overwritten; folders require `recursive: true`. |
| Failed transfers | Throw an exception. Folder downloads and uploads may leave partial results. |

## License

[MIT](LICENSE.md). Created by [Craig Dennis](https://github.com/Zephni).
