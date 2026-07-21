<?php

namespace DNDark\LogicMap\Tests\Unit;

use DNDark\LogicMap\Domain\Snapshot\IndexedFile;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Support\SourceFingerprint;
use PHPUnit\Framework\TestCase;

final class V2IndexSupportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/logic-map-discovery-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/app/Skip', 0755, true);
        mkdir($this->root.'/vendor', 0755, true);
        mkdir($this->root.'/storage', 0755, true);
        file_put_contents($this->root.'/app/B.php', '<?php class B {}');
        file_put_contents($this->root.'/app/A.php', '<?php class A {}');
        file_put_contents($this->root.'/app/Skip/C.php', '<?php class C {}');
        file_put_contents($this->root.'/vendor/V.php', '<?php class V {}');
        file_put_contents($this->root.'/storage/S.php', '<?php class S {}');
        file_put_contents($this->root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
        parent::tearDown();
    }

    public function test_discovers_sorted_php_files_and_applies_default_and_configured_excludes(): void
    {
        $paths = (new RepositoryFileDiscovery($this->root))->discover(new IndexOptions(
            ['app', 'vendor', 'storage'],
            ['app/Skip'],
        ));

        self::assertSame(['app/A.php', 'app/B.php'], $paths);
    }

    public function test_fingerprint_uses_analysis_version_and_content_metadata_but_not_mtime_or_force(): void
    {
        $source = file_get_contents($this->root.'/app/A.php');
        self::assertIsString($source);
        $files = [new IndexedFile('app/A.php', hash('sha256', $source), strlen($source))];
        $normal = new IndexOptions(['app'], [], false);
        $forced = new IndexOptions(['app'], [], true);
        $first = (new SourceFingerprint('2.0-core-1', 1))->calculate($normal, $files);
        touch($this->root.'/app/A.php', time() + 100);
        $afterMtime = (new SourceFingerprint('2.0-core-1', 1))->calculate($forced, $files);
        $afterVersion = (new SourceFingerprint('2.0-core-2', 1))->calculate($normal, $files);
        $afterSemanticConfig = (new SourceFingerprint('2.0-core-1', 1, [
            'modules' => ['explicit' => ['App\\Services\\Order*' => 'Orders']],
        ]))->calculate($normal, $files);

        self::assertSame($first, $afterMtime);
        self::assertNotSame($first, $afterVersion);
        self::assertNotSame($first, $afterSemanticConfig);
    }
}
