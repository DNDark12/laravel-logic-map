<?php

namespace DNDark\LogicMap\Support\Git;

use InvalidArgumentException;
use RuntimeException;

final readonly class GitRefValidator
{
    public function __construct(
        private string $repositoryRoot,
        private NativeGitCommandRunner $runner,
        private int $timeoutMs,
    ) {}

    /** @return array{0: string, 1: string} */
    public function resolvePair(string $base, string $head): array
    {
        $this->assertSyntax($base);
        $this->assertSyntax($head);

        return [$this->resolve($base), $this->resolve($head)];
    }

    public function assertSyntax(string $ref): void
    {
        if ($ref === '' || str_starts_with($ref, '-')
            || preg_match('#^[A-Za-z0-9._/@{}~^:+-]+$#', $ref) !== 1) {
            throw new InvalidArgumentException('Git ref contains disallowed characters or option syntax.');
        }
    }

    private function resolve(string $ref): string
    {
        $result = $this->runner->run([
            'git', 'rev-parse', '--verify', '--end-of-options', $ref.'^{commit}',
        ], $this->repositoryRoot, $this->timeoutMs);
        $commit = trim($result['stdout']);

        if ($result['exit_code'] !== 0 || preg_match('/^[a-f0-9]{40,64}$/', $commit) !== 1) {
            throw new RuntimeException("Git ref {$ref} does not resolve to a commit.");
        }

        return $commit;
    }
}
