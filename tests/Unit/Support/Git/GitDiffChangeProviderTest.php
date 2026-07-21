<?php

namespace DNDark\LogicMap\Tests\Unit\Support\Git;

use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Support\Git\GitDiffChangeProvider;
use DNDark\LogicMap\Support\Git\NativeGitCommandRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GitDiffChangeProviderTest extends TestCase
{
    public function test_parses_add_modify_delete_rename_copy_binary_and_zero_context_hunks(): void
    {
        $runner = new RecordingGitRunner($this->diffFixture());
        $result = (new GitDiffChangeProvider('/repo', $runner, 1000))->changes('main', 'HEAD');
        $files = $result['files'];

        self::assertSame(str_repeat('a', 40), $result['base_commit']);
        self::assertSame(str_repeat('b', 40), $result['head_commit']);
        self::assertSame([
            ChangeType::Added,
            ChangeType::Modified,
            ChangeType::Deleted,
            ChangeType::Renamed,
            ChangeType::Renamed,
            ChangeType::Added,
        ], array_map(static fn ($file): ChangeType => $file->changeType, $files));
        self::assertSame('app/New.php', $files[0]->newPath);
        self::assertSame([0, 0, 1, 3], $files[0]->hunks[0]->toTuple());
        self::assertSame('app/OldName.php', $files[3]->oldPath);
        self::assertSame('app/NewName.php', $files[3]->newPath);
        self::assertSame([], $files[3]->hunks, 'A pure rename remains a rename without invented hunks.');
        self::assertSame([10, 1, 12, 1], $files[4]->hunks[0]->toTuple());
        self::assertSame('app/Copy.php', $files[5]->newPath);
        self::assertCount(1, $result['diagnostics']);
        self::assertSame('binary_file_ignored', $result['diagnostics'][0]->attributes['reason']);

        $diffCall = array_values(array_filter(
            $runner->calls,
            static fn (array $call): bool => ($call['argv'][1] ?? null) === 'diff',
        ))[0];
        self::assertSame([
            'git', 'diff', '--no-ext-diff', '--no-textconv', '--unified=0',
            '--find-renames', '--find-copies', '--end-of-options',
            str_repeat('a', 40), str_repeat('b', 40), '--',
        ], $diffCall['argv']);
    }

    public function test_rejects_dash_prefixed_or_malformed_refs_before_any_process_is_spawned(): void
    {
        foreach (['--upload-pack', '--upload-pack=/tmp/evil', 'bad ref'] as $ref) {
            $runner = new RecordingGitRunner('');

            try {
                (new GitDiffChangeProvider('/repo', $runner, 1000))->changes($ref, 'HEAD');
                self::fail("Ref {$ref} should be rejected.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $runner->calls, $ref);
            }
        }
    }

    private function diffFixture(): string
    {
        return <<<'DIFF'
diff --git a/app/New.php b/app/New.php
new file mode 100644
--- /dev/null
+++ b/app/New.php
@@ -0,0 +1,3 @@
+<?php
+class NewFile {}
+
diff --git a/app/Service.php b/app/Service.php
--- a/app/Service.php
+++ b/app/Service.php
@@ -10 +10 @@
-old();
+newCall();
diff --git a/app/Deleted.php b/app/Deleted.php
deleted file mode 100644
--- a/app/Deleted.php
+++ /dev/null
@@ -4,2 +0,0 @@
-public function gone() {}
-
diff --git a/app/OldName.php b/app/NewName.php
similarity index 100%
rename from app/OldName.php
rename to app/NewName.php
diff --git a/app/OldChanged.php b/app/NewChanged.php
similarity index 80%
rename from app/OldChanged.php
rename to app/NewChanged.php
--- a/app/OldChanged.php
+++ b/app/NewChanged.php
@@ -10 +12 @@
-class OldName {}
+class NewName {}
diff --git a/app/Source.php b/app/Copy.php
similarity index 100%
copy from app/Source.php
copy to app/Copy.php
diff --git a/public/logo.png b/public/logo.png
Binary files a/public/logo.png and b/public/logo.png differ
DIFF;
    }
}

final class RecordingGitRunner extends NativeGitCommandRunner
{
    public array $calls = [];

    public function __construct(private readonly string $diff) {}

    public function run(array $argv, string $workingDirectory, int $timeoutMs): array
    {
        $this->calls[] = compact('argv', 'workingDirectory', 'timeoutMs');

        if (($argv[1] ?? null) === 'rev-parse') {
            $ref = $argv[count($argv) - 1];

            return [
                'stdout' => str_starts_with($ref, 'main') ? str_repeat('a', 40)."\n" : str_repeat('b', 40)."\n",
                'stderr' => '',
                'exit_code' => 0,
            ];
        }

        return ['stdout' => $this->diff, 'stderr' => '', 'exit_code' => 0];
    }
}
