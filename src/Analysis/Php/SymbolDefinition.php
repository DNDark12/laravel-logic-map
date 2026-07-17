<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use InvalidArgumentException;

final readonly class SymbolDefinition
{
    public function __construct(
        public NodeId $id,
        public NodeKind $structuralKind,
        public string $name,
        public ?string $qualifiedName,
        public SourceLocation $location,
        public array $declaredParameterTypes = [],
        public array $declaredPropertyTypes = [],
        public ?string $declaredReturnType = null,
        public array $attributes = [],
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Symbol definitions require a name.');
        }

        if (! in_array($structuralKind, [
            NodeKind::ClassSymbol,
            NodeKind::InterfaceSymbol,
            NodeKind::TraitSymbol,
            NodeKind::EnumSymbol,
            NodeKind::Method,
        ], true)) {
            throw new InvalidArgumentException('Symbol definitions require a structural class-like or method kind.');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'structural_kind' => $this->structuralKind->value,
            'name' => $this->name,
            'qualified_name' => $this->qualifiedName,
            'location' => $this->location->toArray(),
            'declared_parameter_types' => $this->declaredParameterTypes,
            'declared_property_types' => $this->declaredPropertyTypes,
            'declared_return_type' => $this->declaredReturnType,
            'attributes' => $this->attributes,
        ];
    }
}
