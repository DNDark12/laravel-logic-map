<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Impact;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Impact\ChangedFile;
use DNDark\LogicMap\Domain\Impact\ChangedHunk;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Services\Impact\BaseRefSymbolResolver;
use DNDark\LogicMap\Support\Git\GitObjectReader;
use DNDark\LogicMap\Support\Git\NativeGitCommandRunner;
use PHPUnit\Framework\TestCase;

final class BaseRefSymbolResolverTest extends TestCase
{
    public function test_deleted_method_survives_from_base_and_links_exact_unresolved_callers(): void
    {
        $base = <<<'PHP'
<?php
namespace App;
final class Gateway
{
    public function deleted(): void {}
}
PHP;
        $active = (new PhpFileParser())->parse('app/Gateway.php', <<<'PHP'
<?php
namespace App;
final class Gateway {}
PHP);
        $table = new SymbolTable();

        foreach ($active->symbols as $symbol) {
            $table->add($symbol);
        }

        $diagnostic = new Diagnostic(
            DiagnosticCode::UnresolvedTarget,
            'resolve',
            'app/Caller.php',
            10,
            10,
            'Known method target is missing.',
            [
                'enclosing_symbol_id' => 'method:App\Caller::run',
                'attempted_target_id' => 'method:App\Gateway::deleted',
                'call_site_evidence_id' => str_repeat('c', 64),
            ],
        );
        $runner = new ObjectGitRunner($base);
        $resolver = new BaseRefSymbolResolver(
            new GitObjectReader('/repo', $runner, 1000),
            new PhpFileParser(),
            $table,
            [$diagnostic],
        );
        $result = $resolver->resolve(
            new ChangedFile(ChangeType::Deleted, 'app/Gateway.php', null, [
                new ChangedHunk(5, 1, 0, 0),
            ]),
            str_repeat('a', 40),
            str_repeat('b', 40),
        );

        self::assertSame('method:App\Gateway::deleted', $result['symbols'][0]->oldNodeId?->value);
        self::assertNull($result['symbols'][0]->newNodeId);
        self::assertSame(['method:App\Caller::run'], $result['symbols'][0]->attributes['diagnostic_callers']);
        self::assertSame([str_repeat('c', 64)], $result['symbols'][0]->attributes['diagnostic_evidence_ids']);
        self::assertSame([], $result['diagnostics']);
        self::assertSame([
            'git', 'show', '--no-ext-diff', '--no-textconv', '--end-of-options',
            str_repeat('a', 40).':app/Gateway.php',
        ], $runner->calls[0]);
    }

    public function test_renamed_symbol_pairs_old_and_new_ids_instead_of_emitting_add_delete(): void
    {
        $runner = new ObjectGitRunner(<<<'PHP'
<?php
namespace App;
final class OldName
{
    public function run(): void {}
}
PHP);
        $new = (new PhpFileParser())->parse(
            'app/NewName.php',
            <<<'PHP'
<?php
namespace App;
final class NewName
{
    public function execute(): void {}
}
PHP,
        );
        $table = new SymbolTable();

        foreach ($new->symbols as $symbol) {
            $table->add($symbol);
        }

        $resolver = new BaseRefSymbolResolver(
            new GitObjectReader('/repo', $runner, 1000),
            new PhpFileParser(),
            $table,
            [],
        );
        $result = $resolver->resolve(
            new ChangedFile(ChangeType::Renamed, 'app/OldName.php', 'app/NewName.php', [
                new ChangedHunk(3, 1, 3, 1),
            ]),
            str_repeat('a', 40),
            str_repeat('b', 40),
        );
        $pairs = array_map(static fn ($symbol): string => implode('|', [
            $symbol->oldNodeId?->value,
            $symbol->newNodeId?->value,
            $symbol->changeType->value,
        ]), $result['symbols']);

        self::assertContains('class:App\OldName|class:App\NewName|renamed', $pairs);
    }

    public function test_pure_rename_matches_unchanged_canonical_identity_directly(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
final class StableName
{
    public function run(): void {}
}
PHP;
        $active = (new PhpFileParser())->parse('app/Moved/StableName.php', $source);
        $table = new SymbolTable();

        foreach ($active->symbols as $symbol) {
            $table->add($symbol);
        }

        $resolver = new BaseRefSymbolResolver(
            new GitObjectReader('/repo', new ObjectGitRunner($source), 1000),
            new PhpFileParser(),
            $table,
            [],
        );
        $result = $resolver->resolve(
            new ChangedFile(ChangeType::Renamed, 'app/StableName.php', 'app/Moved/StableName.php', []),
            str_repeat('a', 40),
            str_repeat('b', 40),
        );
        $pairs = array_map(static fn ($symbol): string => implode('|', [
            $symbol->oldNodeId?->value,
            $symbol->newNodeId?->value,
        ]), $result['symbols']);

        self::assertContains('class:App\StableName|class:App\StableName', $pairs);
        self::assertContains('method:App\StableName::run|method:App\StableName::run', $pairs);
        self::assertSame([], $result['diagnostics']);
    }

    public function test_unreadable_unparseable_and_ambiguous_base_content_fall_back_with_diagnostics(): void
    {
        $cases = [
            [new ObjectGitRunner('', 1), DiagnosticCode::GitObjectUnreadable, new ChangedHunk(1, 1, 0, 0)],
            [new ObjectGitRunner('<?php final class Broken { public function x( }'), DiagnosticCode::BaseParseFailed, new ChangedHunk(1, 1, 0, 0)],
            [new ObjectGitRunner(<<<'PHP'
<?php
namespace App;
final class Duplicate
{
    public function first(): void {}
    public function second(): void {}
}
PHP), DiagnosticCode::BaseSymbolAmbiguous, new ChangedHunk(5, 2, 0, 0)],
        ];

        foreach ($cases as [$runner, $expected, $hunk]) {
            $resolver = new BaseRefSymbolResolver(
                new GitObjectReader('/repo', $runner, 1000),
                new PhpFileParser(),
                new SymbolTable(),
                [],
            );
            $file = new ChangedFile(ChangeType::Deleted, 'app/Broken.php', null, [$hunk]);
            $result = $resolver->resolve($file, str_repeat('a', 40), str_repeat('b', 40));

            self::assertSame($expected, $result['diagnostics'][0]->code);
            self::assertSame('file:app/Broken.php', $result['symbols'][0]->oldNodeId?->value);
        }
    }
}

final class ObjectGitRunner extends NativeGitCommandRunner
{
    public array $calls = [];

    public function __construct(
        private readonly string $source,
        private readonly int $exitCode = 0,
    ) {}

    public function run(array $argv, string $workingDirectory, int $timeoutMs): array
    {
        $this->calls[] = $argv;

        return [
            'stdout' => $this->source,
            'stderr' => $this->exitCode === 0 ? '' : 'missing object',
            'exit_code' => $this->exitCode,
        ];
    }
}
