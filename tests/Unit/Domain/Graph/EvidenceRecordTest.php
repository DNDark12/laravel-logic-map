<?php

namespace DNDark\LogicMap\Tests\Unit\Domain\Graph;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvidenceRecordTest extends TestCase
{
    public function test_serializes_complete_evidence_and_has_a_deterministic_id(): void
    {
        $first = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'eloquent-write',
            Certainty::Certain,
            new SourceLocation('app\\Services\\OrderService.php', 42, 43),
            '$order->update(["status" => "cancelled"])',
            '$order->canBeCancelled()',
            ['operation' => 'update', 'columns' => ['status']],
        );
        $second = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'eloquent-write',
            Certainty::Certain,
            new SourceLocation('app/Services/OrderService.php', 42, 43),
            '$order->update(["status" => "cancelled"])',
            '$order->canBeCancelled()',
            ['columns' => ['status'], 'operation' => 'update'],
        );

        self::assertSame([
            'origin' => 'static_ast',
            'detector' => 'eloquent-write',
            'certainty' => 'certain',
            'location' => [
                'file' => 'app/Services/OrderService.php',
                'start_line' => 42,
                'end_line' => 43,
            ],
            'expression' => '$order->update(["status" => "cancelled"])',
            'condition' => '$order->canBeCancelled()',
            'attributes' => ['operation' => 'update', 'columns' => ['status']],
        ], $first->toArray());
        self::assertSame($first->id(), $second->id());
    }

    public function test_source_location_rejects_unsafe_paths_and_invalid_spans(): void
    {
        foreach (['/etc/passwd', 'C:\\repo\\Foo.php', '\\\\server\\share\\Foo.php', 'app/../Foo.php'] as $unsafe) {
            try {
                new SourceLocation($unsafe, 1, 1);
                self::fail("Expected rejection of unsafe path: {$unsafe}");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new SourceLocation('app/Foo.php', 3, 2);
    }

    public function test_requires_a_detector_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EvidenceRecord(EvidenceOrigin::StaticAst, '', Certainty::Possible);
    }
}
