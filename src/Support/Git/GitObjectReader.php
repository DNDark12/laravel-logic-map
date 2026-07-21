<?php

namespace DNDark\LogicMap\Support\Git;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;
use RuntimeException;

final readonly class GitObjectReader
{
    public function __construct(
        private string $repositoryRoot,
        private NativeGitCommandRunner $runner,
        private int $timeoutMs,
    ) {}

    public function read(string $commit, string $path): string
    {
        if (preg_match('/^[a-f0-9]{40,64}$/', $commit) !== 1) {
            throw new InvalidArgumentException('Git object reads require a validated commit ID.');
        }

        $path = RelativePath::normalize($path);
        $result = $this->runner->run([
            'git', 'show', '--no-ext-diff', '--no-textconv', '--end-of-options', $commit.':'.$path,
        ], $this->repositoryRoot, $this->timeoutMs);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Git object could not be read: '.trim($result['stderr']));
        }

        return $result['stdout'];
    }
}
