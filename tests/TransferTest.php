<?php

use Illuminate\Config\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WebRegulate\DevCompanion\Classes\BaseCommand;

require_once __DIR__.'/../src/Classes/BaseCommand.php';

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/dev-companion-'.bin2hex(random_bytes(8));
    $application = new Application(getcwd());
    $application->instance('config', new Repository([
        'dev-companion' => ['ssh_connections' => [
            'test' => ['host' => 'example.test', 'user' => 'tester', 'port' => 2222, 'base_path' => '/srv/project'],
        ]],
    ]));
    $application->instance('files', new Filesystem);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($application);
    $this->output = new BufferedOutput;
    $this->command = new BaseCommand;
    $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
});

afterEach(function () {
    File::deleteDirectory($this->directory);
    Facade::clearResolvedInstances();
});

it('replaces a local file only after downloading successfully', function () {
    File::ensureDirectoryExists($this->directory);
    $destination = $this->directory.'/database with spaces.sqlite';
    File::put($destination, 'original');
    $command = $this->command;
    Process::fake(function (PendingProcess $process) {
        expect($this->output->fetch())
            ->toContain('Downloading file from remote: tester@example.test:/srv/project/database with spaces.sqlite')
            ->toContain('Connection: test (port 2222)')
            ->toContain('The existing local file will be replaced only after the download succeeds.')
            ->not->toContain('Download complete.')
            ->not->toContain('to local:')
            ->not->toContain('.dev-companion-');
        File::put(end($process->command), 'downloaded');

        return Process::result();
    })->preventStrayProcesses();

    $result = $command->download('test', '{base_path}/database with spaces.sqlite', $destination, replace: true);

    expect($result)->toBe($command);
    expect(File::get($destination))->toBe('downloaded');
    expect(glob($this->directory.'/.dev-companion-*'))->toBe([]);
    expect($this->output->fetch())
        ->toContain("Downloaded file from remote: tester@example.test:/srv/project/database with spaces.sqlite\nto local: {$destination}")
        ->toContain('Download complete. Existing local file replaced.');
    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 0, 7) === [
        'scp', '-s', '-B', '-P', '2222', '--', 'tester@example.test:/srv/project/database with spaces.sqlite',
    ]);
});

it('uses white labels and yellow paths when downloading a file', function (bool $replace) {
    $destination = $this->directory.'/new.txt';
    if ($replace) {
        File::ensureDirectoryExists($this->directory);
        File::put($destination, 'original');
    }

    $this->output->setDecorated(true);
    Process::fake(function (PendingProcess $process) {
        File::put(end($process->command), 'downloaded');

        return Process::result();
    })->preventStrayProcesses();

    $this->command->download('test', 'new.txt', $destination, replace: $replace);

    $output = $this->output->fetch();
    expect($output)
        ->toContain("\033[37mDownloading file from remote: ")
        ->toContain("\033[32mDownload complete.")
        ->toContain("\033[37mDownloaded file from remote: ")
        ->toContain("\033[33mtester@example.test:/srv/project/new.txt")
        ->toContain("\033[37mto local: ")
        ->toContain("\033[33m{$destination}");
    if ($replace) {
        expect($output)->toContain("\033[37mThe existing local file will be replaced only after the download succeeds.");
    }

    expect(File::get($destination))->toBe('downloaded');
})->with([false, true]);

it('requires explicit replacement for an existing download destination', function () {
    File::ensureDirectoryExists($this->directory);
    $destination = $this->directory.'/test.txt';
    File::put($destination, 'original');
    Process::fake()->preventStrayProcesses();

    expect(fn () => $this->command->download('test', 'test.txt', $destination))
        ->toThrow(RuntimeException::class);

    expect(File::get($destination))->toBe('original');
    Process::assertNothingRan();
});

it('preserves the existing local file when a download fails', function () {
    File::ensureDirectoryExists($this->directory);
    $destination = $this->directory.'/database.sqlite';
    File::put($destination, 'original');
    Process::fake(fn () => Process::result(errorOutput: 'Connection failed', exitCode: 1))->preventStrayProcesses();

    expect(fn () => $this->command->download('test', 'database.sqlite', $destination, replace: true))
        ->toThrow(ProcessFailedException::class);

    expect(File::get($destination))->toBe('original');
    expect(glob($this->directory.'/.dev-companion-*'))->toBe([]);
    expect($this->output->fetch())
        ->toContain('Downloading file from remote:')
        ->toContain('Download failed:')
        ->toContain('The local destination was not replaced.')
        ->not->toContain('Download complete.')
        ->not->toContain('Downloaded file from');
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'scp', 1);
});

it('uploads a file to the configured remote base path', function () {
    File::ensureDirectoryExists($this->directory);
    $source = $this->directory.'/test file.txt';
    File::put($source, 'fixture');
    Process::fake(function () {
        expect($this->output->fetch())
            ->toContain('Uploading file to remote: tester@example.test:/srv/project/test file.txt')
            ->toContain('Existing remote files with matching names may be overwritten.')
            ->not->toContain('Upload complete.')
            ->not->toContain('from local:');

        return Process::result();
    })->preventStrayProcesses();
    $command = $this->command;

    expect($command->upload('test', $source, 'test file.txt'))->toBe($command);

    expect($this->output->fetch())
        ->toContain("Uploaded file from local: {$source}\nto remote: tester@example.test:/srv/project/test file.txt")
        ->toContain('Upload complete.');
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'scp', '-s', '-B', '-P', '2222', '--', $source, 'tester@example.test:/srv/project/test file.txt',
    ]);
});

it('does not report success when an upload fails', function () {
    File::ensureDirectoryExists($this->directory);
    $source = $this->directory.'/test.txt';
    File::put($source, 'fixture');
    Process::fake(fn () => Process::result(exitCode: 1))->preventStrayProcesses();

    expect(fn () => $this->command->upload('test', $source, 'test.txt'))
        ->toThrow(ProcessFailedException::class);

    expect($this->output->fetch())
        ->toContain('Uploading file to remote:')
        ->toContain('Upload failed:')
        ->toContain('The remote destination may contain partially uploaded files.')
        ->not->toContain('Upload complete.')
        ->not->toContain('Uploaded file from');
});

it('supports recursive folder transfers in both directions', function () {
    File::ensureDirectoryExists($this->directory);
    Process::fake()->preventStrayProcesses();
    $command = $this->command;

    $command->upload('test', $this->directory, '/tmp/test-folder', recursive: true)
        ->download('test', '/tmp/test-folder', $this->directory, recursive: true, replace: true);

    expect($this->output->fetch())
        ->toContain('Uploading folder recursively to remote:')
        ->toContain('Downloading folder recursively from remote:')
        ->toContain('Folders will be merged; existing local files with matching names may be overwritten.')
        ->toContain("Uploaded folder recursively from local: {$this->directory}\nto remote: tester@example.test:/tmp/test-folder")
        ->toContain("Downloaded folder recursively from remote: tester@example.test:/tmp/test-folder\nto local: {$this->directory}");
    Process::assertRanTimes(fn (PendingProcess $process) => in_array('-r', $process->command, true), 2);
});

it('warns about partial folder contents when a recursive transfer fails', function (string $direction) {
    File::ensureDirectoryExists($this->directory);
    Process::fake(fn () => Process::result(exitCode: 1))->preventStrayProcesses();

    expect(function () use ($direction) {
        if ($direction === 'download') {
            $this->command->download('test', '/tmp/test-folder', $this->directory, recursive: true, replace: true);
        } else {
            $this->command->upload('test', $this->directory, '/tmp/test-folder', recursive: true);
        }
    })->toThrow(ProcessFailedException::class);

    $label = ucfirst($direction);
    $progress = $direction === 'download' ? 'Downloading folder recursively from remote:' : 'Uploading folder recursively to remote:';
    expect($this->output->fetch())
        ->toContain($progress)
        ->toContain("{$label} failed:")
        ->toContain("partially {$direction}ed files.")
        ->not->toContain("{$label} complete.");
})->with(['download', 'upload']);

it('refuses to upload a missing file or a directory without recursive mode', function () {
    File::ensureDirectoryExists($this->directory);
    Process::fake()->preventStrayProcesses();
    $command = $this->command;

    expect(fn () => $command->upload('test', $this->directory.'/missing', '/tmp/test'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $command->upload('test', $this->directory, '/tmp/test'))
        ->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});

it('refuses unknown or unsafe connections before launching a transfer', function () {
    config(['dev-companion.ssh_connections.unsafe' => ['host' => '-oProxyCommand=bad', 'user' => 'tester']]);
    Process::fake()->preventStrayProcesses();
    $command = $this->command;

    expect(fn () => $command->download('missing', '/tmp/test', $this->directory.'/test'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $command->download('unsafe', '/tmp/test', $this->directory.'/test'))
        ->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});