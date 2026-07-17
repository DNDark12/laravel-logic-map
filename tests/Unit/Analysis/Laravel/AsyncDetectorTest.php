<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\LaravelFactReconciler;
use DNDark\LogicMap\Analysis\Php\CallGraphBuilder;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class AsyncDetectorTest extends CommerceFixtureTestCase
{
    public function test_commerce_fixture_distinguishes_sync_async_and_registered_listeners(): void
    {
        [$graph] = $this->buildSemanticGraph();

        $eventDispatch = $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            EdgeType::Dispatches,
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
        );
        self::assertCount(1, $eventDispatch);
        self::assertSame('sync', $eventDispatch[0]->evidence[0]->attributes['execution']);

        $syncListener = $this->edges(
            $graph,
            'class:Fixtures\CommerceApp\Listeners\RestockInventory',
            EdgeType::ListensTo,
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
        );
        $queuedListener = $this->edges(
            $graph,
            'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook',
            EdgeType::ListensTo,
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
        );
        self::assertCount(2, $syncListener);
        self::assertCount(2, $queuedListener);
        self::assertSame(['sync'], $this->executions($syncListener));
        self::assertSame(['async'], $this->executions($queuedListener));
        self::assertCount(2, $this->edges(
            $graph,
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
            EdgeType::Queues,
            'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook',
        ));

        $notification = $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            EdgeType::SendsNotification,
            'class:Fixtures\CommerceApp\Notifications\OrderWasCancelled',
        );
        self::assertCount(1, $notification);
        self::assertSame('async', $notification[0]->evidence[0]->attributes['execution']);

        $job = $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Console\Commands\ReconcileInventory::handle',
            EdgeType::Dispatches,
            'class:Fixtures\CommerceApp\Jobs\ReconcileInventoryJob',
        );
        self::assertCount(1, $job);
        self::assertSame('sync', $job[0]->evidence[0]->attributes['execution']);
        self::assertSame([], $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Console\Commands\ReconcileInventory::handle',
            EdgeType::Queues,
            'class:Fixtures\CommerceApp\Jobs\ReconcileInventoryJob',
        ));

        $mail = $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderMailService::queue',
            EdgeType::SendsMail,
            'class:Fixtures\CommerceApp\Mail\OrderCancelledMail',
        );
        self::assertCount(1, $mail);
        self::assertSame('async', $mail[0]->evidence[0]->attributes['execution']);
        self::assertSame('Fixtures\CommerceApp\Models\User', $mail[0]->evidence[0]->attributes['recipient_type']);
    }

    public function test_supported_dispatch_notification_mail_and_schedule_syntaxes_are_mapped(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

final class DomainEvent {}
final class InventoryJob implements ShouldQueue {}
final class Notice implements ShouldQueue {}
final class Message {}
final class User {}
final class ScheduledHandler { public function run(): void {} }
final class Demo
{
    public function run(User $user, array $users): void
    {
        event(new DomainEvent());
        LaravelEvent::dispatch(new DomainEvent());
        InventoryJob::dispatch();
        dispatch(new InventoryJob());
        Bus::dispatchSync(new InventoryJob());
        $user->notify(new Notice());
        Notification::send($users, new Notice());
        Mail::to($user)->queue(new Message());
    }
}
PHP;
        $schedule = new BootFact('schedule', 'schedule', [
            'description' => 'fixture handler',
            'expression' => '0 * * * *',
            'timezone' => 'UTC',
            'command' => null,
            'target_class' => 'App\ScheduledHandler',
            'target_method' => 'run',
            'without_overlapping' => true,
            'on_one_server' => true,
            'run_in_background' => false,
        ]);
        $graph = $this->inlineGraph($source, [$schedule]);
        $caller = 'method:App\Demo::run';

        self::assertCount(2, $this->edges($graph, $caller, EdgeType::Dispatches, 'class:App\DomainEvent'));
        self::assertCount(3, $this->edges($graph, $caller, EdgeType::Dispatches, 'class:App\InventoryJob'));
        self::assertCount(2, $this->edges($graph, $caller, EdgeType::Queues, 'class:App\InventoryJob'));
        self::assertCount(2, $this->edges($graph, $caller, EdgeType::SendsNotification, 'class:App\Notice'));
        self::assertCount(1, $this->edges($graph, $caller, EdgeType::SendsMail, 'class:App\Message'));
        self::assertCount(1, $this->edges(
            $graph,
            'schedule:fixture handler',
            EdgeType::Schedules,
            'method:App\ScheduledHandler::run',
        ));
    }

    private function inlineGraph(string $source, array $bootFacts): KnowledgeGraph
    {
        $parsed = (new PhpFileParser())->parse('app/Async.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build([$parsed], $graph);
        (new CallGraphBuilder(new CallTargetResolver($symbols), $symbols))->build([$parsed], $graph);
        (new LaravelFactReconciler())->reconcile([$parsed], $symbols, $bootFacts, $graph);

        return $graph;
    }

    private function executions(array $edges): array
    {
        $executions = array_values(array_unique(array_map(
            static fn (GraphEdge $edge): string => $edge->evidence[0]->attributes['execution'],
            $edges,
        )));
        sort($executions, SORT_STRING);

        return $executions;
    }

    private function edges(KnowledgeGraph $graph, string $source, EdgeType $type, string $target): array
    {
        return array_values(array_filter(
            $graph->edges(),
            static fn (GraphEdge $edge): bool => $edge->source->value === $source
                && $edge->type === $type
                && $edge->target->value === $target,
        ));
    }
}
