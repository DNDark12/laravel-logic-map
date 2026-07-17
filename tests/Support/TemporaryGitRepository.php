<?php

namespace DNDark\LogicMap\Tests\Support;

use DNDark\LogicMap\Support\Git\NativeGitCommandRunner;
use RuntimeException;

final class TemporaryGitRepository
{
    private readonly NativeGitCommandRunner $runner;

    private readonly string $root;

    private ?string $baseCommit = null;

    private ?string $headCommit = null;

    public function __construct(string $sourceRoot)
    {
        if (! is_dir($sourceRoot)) {
            throw new RuntimeException('Temporary Git repository source must exist.');
        }

        $this->root = sys_get_temp_dir().'/logic-map-git-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0755, true);
        $this->copyDirectory($sourceRoot, $this->root);
        $this->runner = new NativeGitCommandRunner();
        $this->git(['git', 'init']);
        $this->git(['git', 'add', '--all']);
        $this->baseCommit = $this->commit('base fixture', '2000-01-01T00:00:00+0000');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function baseCommit(): string
    {
        return $this->baseCommit ?? throw new RuntimeException('Base commit is unavailable.');
    }

    public function headCommit(): string
    {
        return $this->headCommit ?? throw new RuntimeException('Head commit is unavailable.');
    }

    public function applyPatch(string $patchPath): void
    {
        $resolved = realpath($patchPath);

        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException('Fixture patch does not exist.');
        }

        $this->git(['git', 'apply', '--whitespace=nowarn', $resolved]);
        $this->git(['git', 'add', '--all']);
        $this->headCommit = $this->commit('head fixture', '2000-01-01T00:00:01+0000');
    }

    public function remove(): void
    {
        if (! is_dir($this->root) || ! str_starts_with(basename($this->root), 'logic-map-git-')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    private function commit(string $message, string $date): string
    {
        $authorDate = getenv('GIT_AUTHOR_DATE');
        $committerDate = getenv('GIT_COMMITTER_DATE');
        putenv('GIT_AUTHOR_DATE='.$date);
        putenv('GIT_COMMITTER_DATE='.$date);

        try {
            $this->git([
                'git',
                '-c', 'user.name=Laravel Logic Map',
                '-c', 'user.email=logic-map@example.invalid',
                'commit', '--no-gpg-sign', '-m', $message,
            ]);
        } finally {
            $authorDate === false ? putenv('GIT_AUTHOR_DATE') : putenv('GIT_AUTHOR_DATE='.$authorDate);
            $committerDate === false ? putenv('GIT_COMMITTER_DATE') : putenv('GIT_COMMITTER_DATE='.$committerDate);
        }

        return trim($this->git(['git', 'rev-parse', 'HEAD'])['stdout']);
    }

    private function git(array $argv): array
    {
        $result = $this->runner->run($argv, $this->root, 10_000);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Temporary Git command failed: '.trim($result['stderr']));
        }

        return $result;
    }

    private function copyDirectory(string $source, string $target): void
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            $destination = $target.'/'.$relative;

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }

                continue;
            }

            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }

            copy($item->getPathname(), $destination);
        }
    }
}
