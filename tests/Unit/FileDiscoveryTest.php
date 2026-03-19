<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FileDiscoveryTest extends TestCase
{
    protected FileDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = new FileDiscovery();
    }

    #[Test]
    public function it_finds_php_files_in_directory()
    {
        $files = $this->discovery->findFiles([__DIR__ . '/../../src']);

        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringEndsWith('.php', $file);
            $this->assertFileExists($file);
        }
    }

    #[Test]
    public function it_returns_empty_array_for_nonexistent_path()
    {
        $files = $this->discovery->findFiles(['/nonexistent/path']);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    #[Test]
    public function it_handles_single_file_path()
    {
        $singleFile = __DIR__ . '/../../src/LogicMapServiceProvider.php';
        $files = $this->discovery->findFiles([$singleFile]);

        $this->assertCount(1, $files);
        $this->assertEquals($singleFile, $files[0]);
    }

    #[Test]
    public function it_returns_sorted_file_list()
    {
        $files = $this->discovery->findFiles([__DIR__ . '/../../src']);

        $sortedFiles = $files;
        sort($sortedFiles);

        $this->assertEquals($sortedFiles, $files);
    }

    #[Test]
    public function it_handles_multiple_paths()
    {
        $files = $this->discovery->findFiles([
            __DIR__ . '/../../src/Domain',
            __DIR__ . '/../../src/Contracts',
        ]);

        $this->assertNotEmpty($files);

        // Should contain files from both directories
        $hasGraphFile = false;
        $hasContractFile = false;

        foreach ($files as $file) {
            if (str_contains($file, 'Graph.php')) $hasGraphFile = true;
            if (str_contains($file, 'GraphRepository.php')) $hasContractFile = true;
        }

        $this->assertTrue($hasGraphFile, 'Should find Graph.php from Domain');
        $this->assertTrue($hasContractFile, 'Should find GraphRepository.php from Contracts');
    }
}

