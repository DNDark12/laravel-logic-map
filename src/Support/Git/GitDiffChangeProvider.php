<?php

namespace DNDark\LogicMap\Support\Git;

use DNDark\LogicMap\Contracts\GitChangeProvider;
use DNDark\LogicMap\Domain\Impact\ChangedFile;
use DNDark\LogicMap\Domain\Impact\ChangedHunk;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\RelativePath;
use RuntimeException;

final readonly class GitDiffChangeProvider implements GitChangeProvider
{
    public function __construct(
        private string $repositoryRoot,
        private NativeGitCommandRunner $runner,
        private int $timeoutMs,
    ) {}

    public function changes(string $base, string $head): array
    {
        [$baseCommit, $headCommit] = (new GitRefValidator(
            $this->repositoryRoot,
            $this->runner,
            $this->timeoutMs,
        ))->resolvePair($base, $head);
        $result = $this->runner->run([
            'git', 'diff', '--no-ext-diff', '--no-textconv',
            '--unified=0', '--find-renames', '--find-copies',
            '--end-of-options', $baseCommit, $headCommit, '--',
        ], $this->repositoryRoot, $this->timeoutMs);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Git diff failed: '.trim($result['stderr']));
        }

        [$files, $diagnostics] = $this->parse($result['stdout']);

        return [
            'files' => $files,
            'diagnostics' => $diagnostics,
            'base_commit' => $baseCommit,
            'head_commit' => $headCommit,
        ];
    }

    private function parse(string $diff): array
    {
        $blocks = preg_split('/(?=^diff --git )/m', $diff, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $files = [];
        $diagnostics = [];

        foreach ($blocks as $block) {
            [$oldPath, $newPath] = $this->paths($block);

            if (str_contains($block, "\nBinary files ") || str_contains($block, "\nGIT binary patch")) {
                $path = $newPath ?? $oldPath;
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::GitObjectUnreadable,
                    'git_diff',
                    $path,
                    null,
                    null,
                    'Binary file diff was ignored.',
                    ['reason' => 'binary_file_ignored'],
                );

                continue;
            }

            $copy = preg_match('/^copy from (.+)$/m', $block) === 1;
            $rename = preg_match('/^rename from (.+)$/m', $block) === 1;
            $type = match (true) {
                $copy, str_contains($block, "\nnew file mode "), $oldPath === null => ChangeType::Added,
                str_contains($block, "\ndeleted file mode "), $newPath === null => ChangeType::Deleted,
                $rename => ChangeType::Renamed,
                default => ChangeType::Modified,
            };
            preg_match_all(
                '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/m',
                $block,
                $matches,
                PREG_SET_ORDER,
            );
            $hunks = array_map(static fn (array $match): ChangedHunk => new ChangedHunk(
                (int) $match[1],
                isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 1,
                (int) $match[3],
                isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 1,
            ), $matches);
            $files[] = new ChangedFile($type, $oldPath, $newPath, $hunks);
        }

        return [$files, $diagnostics];
    }

    private function paths(string $block): array
    {
        $old = null;
        $new = null;

        if (preg_match('/^(?:rename|copy) from (.+)$/m', $block, $match) === 1) {
            $old = $this->path($match[1]);
        }

        if (preg_match('/^(?:rename|copy) to (.+)$/m', $block, $match) === 1) {
            $new = $this->path($match[1]);
        }

        if ($old === null && preg_match('/^--- (.+)$/m', $block, $match) === 1) {
            $old = trim($match[1]) === '/dev/null' ? null : $this->path($match[1], 'a/');
        }

        if ($new === null && preg_match('/^\+\+\+ (.+)$/m', $block, $match) === 1) {
            $new = trim($match[1]) === '/dev/null' ? null : $this->path($match[1], 'b/');
        }

        if ($old === null && $new === null
            && preg_match('/^diff --git a\/(.+) b\/(.+)$/m', $block, $match) === 1) {
            $old = $this->path($match[1]);
            $new = $this->path($match[2]);
        }

        return [$old, $new];
    }

    private function path(string $path, string $prefix = ''): string
    {
        $path = trim($path, " \t\r\n\"");

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        return RelativePath::normalize($path);
    }
}
