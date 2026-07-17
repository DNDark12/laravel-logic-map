<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use PHPUnit\Framework\TestCase;

final class CallTargetResolverTest extends TestCase
{
    public function test_resolves_exact_interface_inherited_and_trait_candidates_without_guessing(): void
    {
        $table = new SymbolTable();
        $this->addClass($table, 'App\Contracts\Gateway', NodeKind::InterfaceSymbol);
        $this->addClass($table, 'App\Repositories\DatabaseGateway', attributes: [
            'implements' => ['App\Contracts\Gateway'],
        ]);
        $this->addMethod($table, 'App\Repositories\DatabaseGateway', 'save');
        $this->addClass($table, 'App\BaseService');
        $this->addMethod($table, 'App\BaseService', 'inherited');
        $this->addClass($table, 'App\UsesBase', attributes: ['extends' => ['App\BaseService']]);
        $this->addClass($table, 'App\Concerns\Runs', NodeKind::TraitSymbol);
        $this->addMethod($table, 'App\Concerns\Runs', 'fromTrait');
        $this->addClass($table, 'App\UsesTrait', attributes: ['uses_traits' => ['App\Concerns\Runs']]);

        $resolver = new CallTargetResolver($table);
        $exact = $resolver->resolve($this->call('App\Repositories\DatabaseGateway', 'save'));
        $interface = $resolver->resolve($this->call('App\Contracts\Gateway', 'save'));
        $inherited = $resolver->resolve($this->call('App\UsesBase', 'inherited'));
        $trait = $resolver->resolve($this->call('App\UsesTrait', 'fromTrait'));

        self::assertSame(Certainty::Certain, $exact->candidates[0]->certainty);
        self::assertSame('method:App\Repositories\DatabaseGateway::save', $exact->candidates[0]->symbol->id->value);
        self::assertSame(Certainty::Probable, $interface->candidates[0]->certainty);
        self::assertSame('interface_implementation', $interface->candidates[0]->reason);
        self::assertSame('method:App\BaseService::inherited', $inherited->candidates[0]->symbol->id->value);
        self::assertSame('inherited_method', $inherited->candidates[0]->reason);
        self::assertSame('method:App\Concerns\Runs::fromTrait', $trait->candidates[0]->symbol->id->value);
        self::assertSame('trait_method', $trait->candidates[0]->reason);
    }

    public function test_returns_all_distinct_interface_implementations_but_rejects_duplicate_ambiguity(): void
    {
        $table = new SymbolTable();
        $this->addClass($table, 'App\Contracts\Gateway', NodeKind::InterfaceSymbol);

        foreach (['AGateway', 'BGateway'] as $class) {
            $this->addClass($table, "App\\Repositories\\".$class, attributes: [
                'implements' => ['App\Contracts\Gateway'],
            ]);
            $this->addMethod($table, "App\\Repositories\\".$class, 'save');
        }

        $duplicates = $this->method('App\Duplicate', 'run');
        $table->add($duplicates);
        $table->add(new SymbolDefinition(
            $duplicates->id,
            $duplicates->structuralKind,
            $duplicates->name,
            $duplicates->qualifiedName,
            new SourceLocation('app/Duplicate.php', 20, 25),
            [],
            [],
            null,
            $duplicates->attributes,
        ));

        $resolver = new CallTargetResolver($table);
        $interface = $resolver->resolve($this->call('App\Contracts\Gateway', 'save'));
        $duplicate = $resolver->resolve($this->call('App\Duplicate', 'run'));

        self::assertSame(
            [
                'method:App\Repositories\AGateway::save',
                'method:App\Repositories\BGateway::save',
            ],
            array_map(static fn ($candidate): string => $candidate->symbol->id->value, $interface->candidates),
        );
        self::assertSame([], $duplicate->candidates);
        self::assertSame(DiagnosticCode::AmbiguousTarget, $duplicate->diagnostics[0]->code);
    }

    public function test_unresolved_receiver_emits_a_diagnostic_and_preserves_call_syntax_attributes(): void
    {
        $call = $this->call(null, 'save', [
            'nullsafe' => true,
            'first_class_callable' => true,
        ]);
        $result = (new CallTargetResolver(new SymbolTable()))->resolve($call);

        self::assertSame([], $result->candidates);
        self::assertSame(DiagnosticCode::UnresolvedReceiver, $result->diagnostics[0]->code);
        self::assertTrue($result->diagnostics[0]->attributes['nullsafe']);
        self::assertTrue($result->diagnostics[0]->attributes['first_class_callable']);
    }

    public function test_known_receiver_with_missing_method_emits_an_exact_unresolved_target(): void
    {
        $table = new SymbolTable();
        $this->addClass($table, 'App\Gateway');
        $result = (new CallTargetResolver($table))->resolve($this->call('App\Gateway', 'deleted'));

        self::assertSame([], $result->candidates);
        self::assertSame(DiagnosticCode::UnresolvedTarget, $result->diagnostics[0]->code);
        self::assertSame('method:App\Gateway::deleted', $result->diagnostics[0]->attributes['attempted_target_id']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $result->diagnostics[0]->attributes['call_site_evidence_id'],
        );
    }

    private function call(?string $receiverType, string $target, array $attributes = []): CallSiteFact
    {
        return new CallSiteFact(
            'app/Caller.php',
            10,
            10,
            NodeId::method('App\Caller', 'run'),
            $attributes['nullsafe'] ?? false ? 'nullsafe_method' : 'method',
            '$receiver',
            $receiverType,
            $target,
            [],
            '$receiver->'.$target.'()',
            $attributes + ['nullsafe' => false, 'first_class_callable' => false],
        );
    }

    private function addClass(
        SymbolTable $table,
        string $class,
        NodeKind $kind = NodeKind::ClassSymbol,
        array $attributes = [],
    ): void {
        $table->add(new SymbolDefinition(
            NodeId::symbol($kind, $class),
            $kind,
            substr($class, strrpos($class, '\\') + 1),
            $class,
            new SourceLocation('app/Symbols.php', 1, 100),
            [],
            [],
            null,
            $attributes,
        ));
    }

    private function addMethod(SymbolTable $table, string $class, string $method): void
    {
        $table->add($this->method($class, $method));
    }

    private function method(string $class, string $method): SymbolDefinition
    {
        return new SymbolDefinition(
            NodeId::method($class, $method),
            NodeKind::Method,
            $method,
            $class.'::'.$method,
            new SourceLocation('app/Symbols.php', 5, 10),
            [],
            [],
            null,
            ['owner_id' => 'class:'.$class],
        );
    }
}
