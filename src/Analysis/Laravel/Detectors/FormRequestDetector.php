<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeKind;

final class FormRequestDetector
{
    private const FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

    public function detect(SymbolTable $symbols, KnowledgeGraph $graph): array
    {
        foreach ($symbols->all() as $method) {
            if ($method->structuralKind !== NodeKind::Method) {
                continue;
            }

            foreach ($method->declaredParameterTypes as $parameter => $type) {
                $targets = $symbols->exact(ltrim($type, '?'));

                if (count($targets) !== 1 || ! $this->isFormRequest($targets[0], $symbols)) {
                    continue;
                }

                SemanticEdgeFactory::add(
                    $graph,
                    $method->id,
                    EdgeType::ValidatesWith,
                    $targets[0]->id,
                    EvidenceOrigin::StaticAst,
                    'form_request_detector',
                    Certainty::Certain,
                    $method->location,
                    '$'.$parameter.':'.$type,
                    null,
                    null,
                    ['parameter' => $parameter],
                );
            }
        }

        return [];
    }

    private function isFormRequest(
        SymbolDefinition $symbol,
        SymbolTable $symbols,
        array $visited = [],
    ): bool {
        if (isset($visited[$symbol->id->value])) {
            return false;
        }

        $visited[$symbol->id->value] = true;

        foreach ($symbol->attributes['extends'] ?? [] as $parent) {
            if ($parent === self::FORM_REQUEST) {
                return true;
            }

            $parents = $symbols->exact($parent);

            if (count($parents) === 1 && $this->isFormRequest($parents[0], $symbols, $visited)) {
                return true;
            }
        }

        return false;
    }
}
