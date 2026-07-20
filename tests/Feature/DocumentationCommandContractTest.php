<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

final class DocumentationCommandContractTest extends TestCase
{
    public function test_readme_documents_the_final_install_query_and_consumer_test_surface(): void
    {
        $readme = $this->read('README.md');

        foreach ([
            '## What 2.0 answers',
            '## Installation',
            '`ext-pdo`',
            '## Indexing',
            '## Workflow examples',
            '## Impact examples',
            '## Evidence and certainty',
            '## HTTP viewer protection',
            '## Runtime evidence (opt-in)',
            '## Known static-analysis limits',
            '## Requirements',
            'DNDark\\LogicMap',
        ] as $required) {
            self::assertStringContainsString($required, $readme, $required);
        }

        self::assertStringContainsString('there is no `logic-map.v2.*` wrapper', $readme);
    }

    public function test_documented_commands_match_the_registered_command_surface(): void
    {
        $commands = array_keys(Artisan::all());

        foreach (['logic-map:index', 'logic-map:status', 'logic-map:workflow', 'logic-map:impact', 'logic-map:clear'] as $command) {
            self::assertContains($command, $commands, $command);
        }

        foreach (['logic-map:build', 'logic-map:analyze', 'logic-map:export-docs', 'logic-map:export-note', 'logic-map:clear-cache'] as $command) {
            self::assertNotContains($command, $commands, $command);
        }
    }

    public function test_machine_contract_json_examples_are_valid_and_upgrade_contract_is_explicit(): void
    {
        $api = $this->read('docs/api-v2.md');
        preg_match_all('/```json\s*(.*?)\s*```/s', $api, $matches);
        self::assertGreaterThanOrEqual(7, count($matches[1]));

        foreach ($matches[1] as $json) {
            self::assertIsArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        }

        $upgrade = $this->read('UPGRADING.md');

        foreach ([
            'There is no V1 API, cache, command, route, report, asset, or UI compatibility layer.',
            'Old snapshots cannot be upgraded.',
            'logic-map.storage.connection',
            'local` and `testing',
            'Runtime collection is disabled by default.',
            'Back up any customized published V1 views/assets',
        ] as $required) {
            self::assertStringContainsString($required, $upgrade, $required);
        }
    }

    public function test_manifest_keeps_lowercase_package_identity_and_uppercase_php_namespace(): void
    {
        $manifest = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('dndark/laravel-logic-map', $manifest['name']);
        self::assertSame('src/', $manifest['autoload']['psr-4']['DNDark\\LogicMap\\']);
        self::assertSame('DNDark\\LogicMap\\LogicMapServiceProvider', $manifest['extra']['laravel']['providers'][0]);
        self::assertArrayHasKey('ext-pdo', $manifest['require']);
        self::assertArrayNotHasKey('ext-pdo_sqlite', $manifest['require']);
        self::assertArrayHasKey('nikic/php-parser', $manifest['require']);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
