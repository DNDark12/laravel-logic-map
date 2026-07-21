<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel\Boot;

use DNDark\LogicMap\Analysis\Laravel\Boot\CommandBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ContainerBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\EventBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Laravel\Boot\PolicyBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\RouteBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ScheduleBootCollector;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class BootCollectorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/boot-probe', [BootProbeController::class, 'show'])
            ->middleware('auth')
            ->name('boot.probe');
        $this->app->bind(PaymentGateway::class, StripePaymentGateway::class);
        $this->app->bind('fixture.throwing.binding', static function (): never {
            throw new RuntimeException('collector executed a binding closure');
        });
        Event::listen(BootProbeEvent::class, BootProbeListener::class);
        Gate::policy(BootProbeModel::class, BootProbePolicy::class);
        $this->app->make(Schedule::class)
            ->call(static fn (): null => null)
            ->name('fixture scheduled callback')
            ->daily();
        $this->app->make(ConsoleKernel::class)->registerCommand(new NeverExecuteCommand());
    }

    public function test_collectors_observe_effective_registrations_without_executing_bindings_or_commands(): void
    {
        $container = (new ContainerBootCollector())->collect($this->app);
        $binding = array_values(array_filter(
            $container->facts,
            static fn ($fact): bool => ($fact->attributes['abstract'] ?? null) === PaymentGateway::class,
        ));

        self::assertCount(1, $binding);
        self::assertSame(StripePaymentGateway::class, $binding[0]->attributes['concrete']);
        self::assertFalse($binding[0]->attributes['shared']);
        self::assertNotEmpty(array_filter(
            $container->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === DiagnosticCode::ClosureContainerBinding
                && ($diagnostic->attributes['abstract'] ?? null) === 'fixture.throwing.binding',
        ));

        $routes = (new RouteBootCollector())->collect($this->app)->facts;
        $route = array_values(array_filter(
            $routes,
            static fn ($fact): bool => ($fact->attributes['name'] ?? null) === 'boot.probe',
        ))[0];
        self::assertSame(['GET'], $route->attributes['methods']);
        self::assertSame('boot-probe', $route->attributes['uri']);
        self::assertSame(['auth'], $route->attributes['middleware']);

        self::assertNotEmpty(array_filter(
            (new EventBootCollector())->collect($this->app)->facts,
            static fn ($fact): bool => ($fact->attributes['event'] ?? null) === BootProbeEvent::class,
        ));
        self::assertNotEmpty(array_filter(
            (new PolicyBootCollector())->collect($this->app)->facts,
            static fn ($fact): bool => ($fact->attributes['model'] ?? null) === BootProbeModel::class,
        ));
        self::assertNotEmpty(array_filter(
            (new ScheduleBootCollector())->collect($this->app)->facts,
            static fn ($fact): bool => ($fact->attributes['description'] ?? null) === 'fixture scheduled callback',
        ));
        self::assertNotEmpty(array_filter(
            (new CommandBootCollector())->collect($this->app)->facts,
            static fn ($fact): bool => ($fact->attributes['name'] ?? null) === 'fixture:never-execute',
        ));
        self::assertFalse(NeverExecuteCommand::$executed);
    }

    public function test_application_boot_failure_degrades_to_a_diagnostic(): void
    {
        $inspector = new LaravelBootInspector(
            static fn () => throw new RuntimeException('fixture boot failed'),
            [new RouteBootCollector()],
        );
        $result = $inspector->inspect();

        self::assertSame([], $result->facts);
        self::assertSame(DiagnosticCode::BootInspectionFailed, $result->diagnostics[0]->code);
        self::assertSame('application_boot', $result->diagnostics[0]->attributes['stage']);
        self::assertSame(RuntimeException::class, $result->diagnostics[0]->attributes['exception']);
    }
}

interface PaymentGateway
{
}

final class StripePaymentGateway implements PaymentGateway
{
}

final class BootProbeController
{
    public function show(): void
    {
    }
}

final class BootProbeEvent
{
}

final class BootProbeListener
{
    public function handle(BootProbeEvent $event): void
    {
    }
}

final class BootProbeModel extends Model
{
}

final class BootProbePolicy
{
}

final class NeverExecuteCommand extends Command
{
    public static bool $executed = false;

    protected $signature = 'fixture:never-execute';

    public function handle(): int
    {
        self::$executed = true;

        throw new RuntimeException('collector executed a command');
    }
}
