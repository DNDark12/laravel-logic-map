<?php

namespace dndark\LogicMap\Analysis;

use dndark\LogicMap\Analysis\Visitors\ClassMethodVisitor;
use dndark\LogicMap\Analysis\Visitors\RouteVisitor;
use dndark\LogicMap\Contracts\GraphExtractor;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Support\FileDiscovery;
use Illuminate\Support\Facades\Log;
use PhpParser\Error as ParseError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

class AstParser implements GraphExtractor
{
    protected $parser;

    /** @var array Parse diagnostics */
    protected array $diagnostics = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @inheritDoc
     */
    public function extract(array $scanPaths): Graph
    {
        // Re-use logic or delegate
        $files = [];
        foreach ($scanPaths as $path) {
            $files = array_merge($files, (new FileDiscovery())->findFiles([$path]));
        }

        return $this->parse($files);
    }

    /**
     * Parse the given files and build a graph.
     *
     * @param array<string> $files
     * @return Graph
     */
    public function parse(array $files): Graph
    {
        $this->diagnostics = [
            'total_files' => count($files),
            'parsed_files' => 0,
            'skipped_files' => 0,
            'error_files' => [],
        ];

        $graph = new Graph();
        $traverser = new NodeTraverser();

        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new RouteVisitor($graph));
        $traverser->addVisitor(new ClassMethodVisitor($graph));

        foreach ($files as $file) {
            try {
                if (!file_exists($file)) {
                    $this->diagnostics['skipped_files']++;
                    $this->diagnostics['error_files'][] = [
                        'file' => $file,
                        'error' => 'File not found',
                    ];
                    continue;
                }

                $code = file_get_contents($file);

                if ($code === false) {
                    $this->diagnostics['skipped_files']++;
                    $this->diagnostics['error_files'][] = [
                        'file' => $file,
                        'error' => 'Could not read file',
                    ];
                    continue;
                }

                $stmts = $this->parser->parse($code);

                if ($stmts) {
                    $traverser->traverse($stmts);
                    $this->diagnostics['parsed_files']++;
                } else {
                    $this->diagnostics['skipped_files']++;
                }
            } catch (ParseError $e) {
                $this->diagnostics['skipped_files']++;
                $this->diagnostics['error_files'][] = [
                    'file' => $file,
                    'error' => $e->getMessage(),
                    'line' => $e->getStartLine(),
                ];

                Log::warning('Logic Map: Parse error in file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                    'line' => $e->getStartLine(),
                ]);
            } catch (Throwable $e) {
                $this->diagnostics['skipped_files']++;
                $this->diagnostics['error_files'][] = [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ];

                Log::error('Logic Map: Unexpected error parsing file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Log summary
        if ($this->diagnostics['skipped_files'] > 0) {
            Log::info('Logic Map: Build completed with skipped files', [
                'total' => $this->diagnostics['total_files'],
                'parsed' => $this->diagnostics['parsed_files'],
                'skipped' => $this->diagnostics['skipped_files'],
            ]);
        }

        return $graph;
    }

    /**
     * Get parse diagnostics from last parse operation.
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
