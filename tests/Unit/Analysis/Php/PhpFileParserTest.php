<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use PHPUnit\Framework\TestCase;

final class PhpFileParserTest extends TestCase
{
    public function test_parses_the_core_commerce_fixture_into_stable_symbols_and_calls(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/CommerceApp/app';
        $parser = new PhpFileParser();
        $parsed = [];

        foreach ([
            'Contracts/OrderGateway.php',
            'Repositories/DatabaseOrderGateway.php',
            'Services/OrderService.php',
        ] as $path) {
            $source = file_get_contents($root.'/'.$path);
            self::assertIsString($source);
            $parsed[] = $parser->parse('app/'.$path, $source);
        }

        $symbols = array_merge(...array_map(static fn ($file): array => $file->symbols, $parsed));
        $byId = [];

        foreach ($symbols as $symbol) {
            $byId[$symbol->id->value][] = $symbol;
        }

        self::assertSame(
            NodeKind::InterfaceSymbol,
            $byId['interface:Fixtures\CommerceApp\Contracts\OrderGateway'][0]->structuralKind,
        );
        self::assertSame(
            NodeKind::ClassSymbol,
            $byId['class:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway'][0]->structuralKind,
        );
        self::assertArrayHasKey(
            'class:Fixtures\CommerceApp\Services\OrderService',
            $byId,
        );
        self::assertArrayHasKey(
            'method:Fixtures\CommerceApp\Services\OrderService::__construct',
            $byId,
        );
        self::assertArrayHasKey(
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            $byId,
        );

        /** @var SymbolDefinition $service */
        $service = $byId['class:Fixtures\CommerceApp\Services\OrderService'][0];
        self::assertSame(
            'Fixtures\CommerceApp\Contracts\OrderGateway',
            $service->declaredPropertyTypes['orders'],
        );

        $serviceFile = $parsed[2];
        $saveCall = array_values(array_filter(
            $serviceFile->callSites,
            static fn ($call): bool => $call->targetName === 'save',
        ));

        self::assertCount(1, $saveCall);
        self::assertSame('$this->orders', $saveCall[0]->receiverExpression);
        self::assertSame(
            'Fixtures\CommerceApp\Contracts\OrderGateway',
            $saveCall[0]->receiverType,
        );
        self::assertSame('$this->orders->save($order)', $saveCall[0]->normalizedExpression);

        $repositoryFile = $parsed[1];
        self::assertCount(1, array_filter(
            $repositoryFile->inheritanceFacts,
            static fn ($fact): bool => $fact->relation === 'implements'
                && $fact->sourceSymbolId->value === 'class:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway'
                && $fact->targetQualifiedName === 'Fixtures\CommerceApp\Contracts\OrderGateway',
        ));
    }
}
