<?php

namespace Neo4j\Neo4jLaravel\Schema;

/**
 * Collects schema rules for a Neo4j relationship type (indexes only for now).
 *
 * Does not create relationships.
 *
 * @api
 */
final class Neo4jRelationshipBlueprint
{
    /** @var list<array{type: string, properties: list<string>, name: string|null}> */
    private array $commands = [];

    public function __construct(
        private readonly string $type,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return list<array{type: string, properties: list<string>, name: string|null}>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @param  string|list<string>  $properties
     */
    public function index(string|array $properties, ?string $name = null): self
    {
        $list = is_array($properties) ? array_values($properties) : [$properties];

        if ($list === []) {
            throw new \InvalidArgumentException('At least one property is required.');
        }

        $this->commands[] = [
            'type' => 'index',
            'properties' => $list,
            'name' => $name,
        ];

        return $this;
    }
}
