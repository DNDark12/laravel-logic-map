<?php

namespace DNDark\LogicMap\Tests\Unit\Fixtures;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Tests\Support\CommerceFixtureLoader;
use PHPUnit\Framework\TestCase;

final class CommerceFixtureContractTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = dirname(__DIR__, 2).'/Fixtures/CommerceApp';
    }

    public function test_cancellation_fixture_preserves_every_required_acceptance_semantic(): void
    {
        $service = $this->contents('app/Services/OrderService.php');
        $controller = $this->contents('app/Http/Controllers/OrderController.php');
        $request = $this->contents('app/Http/Requests/CancelOrderRequest.php');
        $provider = $this->contents('app/Providers/CommerceServiceProvider.php');
        $routes = $this->contents('routes/web.php');

        foreach ([
            'if (! $order->canBeCancelled())',
            'throw new OrderCannotBeCancelledException($order)',
            'DB::transaction(',
            '$order->status = \'cancelled\'',
            "->increment('quantity')",
            'OrderCancelled::dispatch($order)',
            'Cache::forget("order-summary:{$order->getKey()}")',
            '$order->user->notify(new OrderWasCancelled($order))',
            '$this->orders->save($order)',
        ] as $required) {
            self::assertStringContainsString($required, $service);
        }

        self::assertStringContainsString('CancelOrderRequest $request', $controller);
        self::assertStringContainsString('Gate::authorize(\'cancel\', $order)', $controller);
        self::assertStringContainsString("'reason' => ['nullable', 'string', 'max:500']", $request);
        self::assertStringContainsString('OrderGateway::class, DatabaseOrderGateway::class', $provider);
        self::assertStringContainsString('Gate::policy(Order::class, OrderPolicy::class)', $provider);
        self::assertStringContainsString('Event::listen(OrderCancelled::class, RestockInventory::class)', $provider);
        self::assertStringContainsString(
            'Event::listen(OrderCancelled::class, SendCancellationWebhook::class)',
            $provider,
        );
        self::assertStringContainsString("\$schedule->command('inventory:reconcile')->daily()", $provider);
        self::assertStringContainsString("Route::post('/orders/{order}/cancel'", $routes);
        self::assertStringContainsString("Route::get('/orders/{order}'", $routes);
        self::assertStringContainsString("->middleware(['auth', 'throttle:orders'])", $routes);
        self::assertStringContainsString("Route::post('/orders/{order}/ship'", $routes);
        self::assertStringContainsString("Route::get('/dashboard/sales'", $routes);
        self::assertStringContainsString("protected \$signature = 'inventory:reconcile'", $this->contents(
            'app/Console/Commands/ReconcileInventory.php',
        ));
        self::assertStringContainsString(
            'Bus::dispatchSync(new ReconcileInventoryJob())',
            $this->contents('app/Console/Commands/ReconcileInventory.php'),
        );
        self::assertStringContainsString(
            'Mail::to($user)->queue(new OrderCancelledMail($order))',
            $this->contents('app/Services/OrderMailService.php'),
        );
        self::assertStringContainsString(
            "view('orders.show'",
            $controller,
        );
        self::assertStringContainsString(
            "config('logic-map.fixture.audit_disk')",
            $this->contents('app/Services/OrderArtifactService.php'),
        );
        self::assertStringContainsString(
            'Storage::disk($disk)->put(',
            $this->contents('app/Services/OrderArtifactService.php'),
        );
        self::assertStringContainsString(
            "config('services.erp.base_url').'/orders/'",
            $this->contents('app/Listeners/SendCancellationWebhook.php'),
        );

        $dispatch = strpos($service, 'OrderCancelled::dispatch($order)');
        $cache = strpos($service, 'Cache::forget("order-summary:{$order->getKey()}")');
        $notification = strpos($service, '$order->user->notify(new OrderWasCancelled($order))');
        self::assertIsInt($dispatch);
        self::assertIsInt($cache);
        self::assertIsInt($notification);
        self::assertLessThan($cache, $dispatch);
        self::assertLessThan($notification, $cache);

        $ordered = [
            'if (! $order->canBeCancelled())',
            'throw new OrderCannotBeCancelledException($order)',
            'DB::transaction(',
            '$order->status = \'cancelled\'',
            "->increment('quantity')",
            '});',
            'OrderCancelled::dispatch($order)',
            'Cache::forget("order-summary:{$order->getKey()}")',
            '$order->user->notify(new OrderWasCancelled($order))',
        ];
        $offset = 0;

        foreach ($ordered as $semantic) {
            $position = strpos($service, $semantic, $offset);
            self::assertIsInt($position, $semantic);
            $offset = $position + strlen($semantic);
        }
    }

    public function test_every_fixture_php_file_parses_and_the_loader_is_prefix_bounded(): void
    {
        $loader = new CommerceFixtureLoader($this->fixture.'/app');
        $loader->register();

        try {
            self::assertTrue(class_exists('Fixtures\CommerceApp\Models\Order'));
            self::assertFalse($loader->load('App\Models\Order'));
        } finally {
            $loader->unregister();
        }

        $parser = new PhpFileParser();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->fixture) + 1));
            $parsed = $parser->parse($relative, $source);
            self::assertSame([], array_values(array_filter(
                $parsed->diagnostics,
                static fn ($diagnostic): bool => $diagnostic->code === DiagnosticCode::ParseError,
            )), $relative);
        }
    }

    public function test_semantic_edge_golden_is_a_sorted_string_multiset(): void
    {
        $json = json_decode(
            $this->contents('expected/semantic-edges.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($json);
        self::assertSame($json, array_values($json));
        $sorted = $json;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $json);
    }

    private function contents(string $relativePath): string
    {
        $contents = file_get_contents($this->fixture.'/'.$relativePath);
        self::assertIsString($contents, $relativePath);

        return $contents;
    }
}
