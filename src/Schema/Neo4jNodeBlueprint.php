<?php

namespace Neo4j\Neo4jLaravel\Schema;

/**
 * Collects schema rules for a Neo4j node label (indexes / constraints only).
 *
 * Does not create nodes.
 *
 * @api
 */
final class Neo4jNodeBlueprint
{
    /** @var list<array{type: string, properties: list<string>, name: string|null, dimensions?: int, similarity?: string}> */
    private array $commands = [];

    public function __construct(
        private readonly string $label,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return list<array{type: string, properties: list<string>, name: string|null, dimensions?: int, similarity?: string}>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @param  string|list<string>  $properties
     */
    public function unique(string|array $properties, ?string $name = null): self
    {
        $this->commands[] = [
            'type' => 'unique',
            'properties' => $this->normalizeProperties($properties),
            'name' => $name,
        ];

        return $this;
    }

    /**
     * @param  string|list<string>  $properties
     */
    public function index(string|array $properties, ?string $name = null): self
    {
        $this->commands[] = [
            'type' => 'index',
            'properties' => $this->normalizeProperties($properties),
            'name' => $name,
        ];

        return $this;
    }

    public function vector(
        string $property,
        int $dimensions,
        string $similarityFunction = 'cosine',
        ?string $name = null
    ): self {
        $this->commands[] = [
            'type' => 'vector',
            'properties' => [$property],
            'name' => $name,
            'dimensions' => $dimensions,
            'similarity' => $similarityFunction,
        ];

        return $this;
    }

    /**
     * @param  string|list<string>  $properties
     * @return list<string>
     */
    private function normalizeProperties(string|array $properties): array
    {
        $list = is_array($properties) ? array_values($properties) : [$properties];

        if ($list === []) {
            throw new \InvalidArgumentException('At least one property is required.');
        }

        return $list;
    }
}
