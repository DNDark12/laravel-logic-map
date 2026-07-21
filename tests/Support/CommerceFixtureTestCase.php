<?php

namespace DNDark\LogicMap\Tests\Support;

use DNDark\LogicMap\Analysis\Laravel\Boot\ContainerBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\CommandBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\EventBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Laravel\Boot\PolicyBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\RouteBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ScheduleBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\LaravelRegistrationFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\BranchConditionFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\EloquentChainFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\FacadeEffectFactCollector;
use DNDark\LogicMap\Analysis\Laravel\LaravelSemanticAnalyzer;
use DNDark\LogicMap\Analysis\Php\CallGraphBuilder;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Tests\TestCase;
use Fixtures\CommerceApp\Providers\CommerceServiceProvider;

abstract class CommerceFixtureTestCase extends TestCase
{
    private ?CommerceFixtureLoader $commerceLoader = null;

    protected function getPackageProviders($app): array
    {
        $this->loader()->register();

        return [
            ...parent::getPackageProviders($app),
            CommerceServiceProvider::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        require $this->fixtureRoot().'/routes/web.php';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('logic-map.scan_paths', ['app', 'routes']);
        $app['config']->set('logic-map.excludes', []);
        $app['config']->set('logic-map.modules.explicit', [
            'Fixtures\CommerceApp\Models\Order' => 'Orders',
            'Fixtures\CommerceApp\Models\User' => 'Orders',
            'Fixtures\CommerceApp\Contracts\OrderGateway' => 'Orders',
            'Fixtures\CommerceApp\Services\OrderService' => 'Orders',
            'Fixtures\CommerceApp\Repositories\DatabaseOrderGateway' => 'Orders',
            'Fixtures\CommerceApp\Http\Controllers\OrderController' => 'Orders',
            'Fixtures\CommerceApp\Http\Requests\CancelOrderRequest' => 'Orders',
            'Fixtures\CommerceApp\Policies\OrderPolicy' => 'Orders',
            'Fixtures\CommerceApp\Exceptions\OrderCannotBeCancelledException' => 'Orders',
            'Fixtures\CommerceApp\Events\OrderCancelled' => 'Orders',
            'Fixtures\CommerceApp\Notifications\OrderWasCancelled' => 'Orders',
            'Fixtures\CommerceApp\Mail\OrderCancelledMail' => 'Orders',
            'Fixtures\CommerceApp\Services\OrderMailService' => 'Orders',
            'Fixtures\CommerceApp\Models\InventoryStock' => 'Inventory',
            'Fixtures\CommerceApp\Listeners\RestockInventory' => 'Inventory',
            'Fixtures\CommerceApp\Services\InventoryReconciliationService' => 'Inventory',
            'Fixtures\CommerceApp\Jobs\ReconcileInventoryJob' => 'Inventory',
            'Fixtures\CommerceApp\Console\Commands\ReconcileInventory' => 'Inventory',
            'Fixtures\CommerceApp\Http\Controllers\ShippingController' => 'Shipping',
            'Fixtures\CommerceApp\Services\ShippingService' => 'Shipping',
            'Fixtures\CommerceApp\Http\Controllers\DashboardController' => 'Dashboard',
            'Fixtures\CommerceApp\Services\SalesDashboardService' => 'Dashboard',
            'Fixtures\CommerceApp\Listeners\SendCancellationWebhook' => 'Integration',
            'Fixtures\CommerceApp\Listeners\SendOrderCancellationWebhook' => 'Integration',
            'Fixtures\CommerceApp\Services\OrderArtifactService' => 'Integration',
        ]);
        $app['config']->set('logic-map.modules.namespace_roots', ['Fixtures\\CommerceApp\\' => 1]);
        $app['config']->set('logic-map.modules.directory_roots', ['app/Modules', 'app/Domain']);
        $app['config']->set('logic-map.modules.fallback', 'Core');
        $app['config']->set('logic-map.classifier.namespace_conventions', [
            'Services' => 'service',
            'Repositories' => 'repository',
        ]);
    }

    protected function tearDown(): void
    {
        $this->commerceLoader?->unregister();
        parent::tearDown();
    }

    protected function fixtureRoot(): string
    {
        return dirname(__DIR__).'/Fixtures/CommerceApp';
    }

    protected function buildSemanticGraph(array $scanRoots = ['app', 'routes', 'tests']): array
    {
        $parser = new PhpFileParser([
            new LaravelRegistrationFactCollector(),
            new BranchConditionFactCollector(),
            new EloquentChainFactCollector(),
            new FacadeEffectFactCollector(),
        ]);
        $symbols = new SymbolTable();
        $files = [];
        foreach ($scanRoots as $scanRoot) {
            $root = $this->fixtureRoot().'/'.$scanRoot;

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                $relative = str_replace('\\', '/', substr(
                    $file->getPathname(),
                    strlen($this->fixtureRoot()) + 1,
                ));
                $parsed = $parser->parse($relative, $source);
                $files[] = $parsed;

                foreach ($parsed->symbols as $symbol) {
                    $symbols->add($symbol);
                }
            }
        }

        $graph = new KnowledgeGraph();
        $diagnostics = (new StructuralGraphBuilder($symbols))->build($files, $graph);
        $diagnostics = [
            ...$diagnostics,
            ...(new CallGraphBuilder(new CallTargetResolver($symbols), $symbols))->build($files, $graph),
        ];
        $boot = (new LaravelBootInspector(
            fn () => $this->app,
            [
                new RouteBootCollector(),
                new ContainerBootCollector(),
                new EventBootCollector(),
                new PolicyBootCollector(),
                new ScheduleBootCollector(),
                new CommandBootCollector(),
            ],
        ))->inspect($symbols, $files);
        $semantic = (new LaravelSemanticAnalyzer())->analyze($files, $symbols, $boot->facts, $graph);
        $diagnostics = [...$diagnostics, ...$boot->diagnostics, ...$semantic['diagnostics']];

        return [$graph, $diagnostics, $files, $symbols, $boot->facts, $semantic['outputs']];
    }

    private function loader(): CommerceFixtureLoader
    {
        return $this->commerceLoader ??= new CommerceFixtureLoader($this->fixtureRoot().'/app');
    }
}
