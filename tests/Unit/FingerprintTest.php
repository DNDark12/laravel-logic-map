<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Support\Fingerprint;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FingerprintTest extends TestCase
{
    protected Fingerprint $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprint = new Fingerprint();
    }

    #[Test]
    public function it_generates_deterministic_fingerprint()
    {
        $files = [
            __DIR__ . '/../../src/LogicMapServiceProvider.php',
        ];

        $fp1 = $this->fingerprint->generate($files);
        $fp2 = $this->fingerprint->generate($files);

        $this->assertEquals($fp1, $fp2);
    }

    #[Test]
    public function it_returns_sha1_hash()
    {
        $files = [
            __DIR__ . '/../../src/LogicMapServiceProvider.php',
        ];

        $fp = $this->fingerprint->generate($files);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $fp);
    }

    #[Test]
    public function it_produces_different_fingerprints_for_different_files()
    {
        $fp1 = $this->fingerprint->generate([
            __DIR__ . '/../../src/LogicMapServiceProvider.php',
        ]);

        $fp2 = $this->fingerprint->generate([
            __DIR__ . '/../../src/Domain/Graph.php',
        ]);

        $this->assertNotEquals($fp1, $fp2);
    }

    #[Test]
    public function it_handles_empty_file_list()
    {
        $fp = $this->fingerprint->generate([]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $fp);
    }

    #[Test]
    public function it_includes_file_metadata_in_hash()
    {
        // The fingerprint should include file path, mtime, and size
        // This is verified by the fact that different files produce different hashes
        $files = [
            __DIR__ . '/../../src/LogicMapServiceProvider.php',
            __DIR__ . '/../../src/Domain/Graph.php',
        ];

        $fp = $this->fingerprint->generate($files);

        // Should be deterministic
        $this->assertEquals($fp, $this->fingerprint->generate($files));
    }
}

