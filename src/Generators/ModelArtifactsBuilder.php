<?php

namespace CoreSuit\MigrationGen\Generators;

use Illuminate\Support\Str;

class ModelArtifactsBuilder
{
    public function build(array $fields): array
    {
        $fillable = [];
        $casts = [];
        $relations = [];
        $phpDoc = [
            ' * @property int $id',
            ' * @property \Carbon\CarbonImmutable|null $created_at',
            ' * @property \Carbon\CarbonImmutable|null $updated_at',
            ' * @property \Carbon\CarbonImmutable|null $deleted_at',
        ];

        foreach ($fields as $field) {
            $name = $field['name'];
            $fillable[] = "'{$name}',";
            $phpDoc[] = " * @property {$this->phpDocType($field['type'])}" . ($field['required'] ? '' : '|null') . " \${$name}";

            if ($cast = $this->castFor($field['type'], $name)) {
                $casts[] = $cast;
            }

            if (Str::endsWith($name, '_id')) {
                $relatedStudly = Str::of($name)->beforeLast('_id')->studly()->toString();
                $method = Str::of($relatedStudly)->camel()->toString();
                $relations[] =
                    "    public function {$method}()\n" .
                    "    {\n" .
                    "        return \$this->belongsTo(\\App\\Models\\{$relatedStudly}::class);\n" .
                    "    }";
            }
        }

        if (empty($casts))     { $casts[] = "//"; }
        if (empty($relations)) { $relations[] = "//"; }

        return [$fillable, $casts, $relations, $phpDoc];
    }

    private function phpDocType(string $type): string
    {
        return match ($type) {
            'int', 'integer', 'bigint' => 'int',
            'decimal'                  => 'string|float',
            'bool', 'boolean'          => 'bool',
            'date', 'datetime'         => '\Carbon\CarbonInterface',
            'json'                     => 'array',
            'text', 'string'           => 'string',
            default                    => 'mixed',
        };
    }

    private function castFor(string $type, string $name): ?string
    {
        return match ($type) {
            'decimal'        => "'{$name}' => 'decimal:2',",
            'bool', 'boolean'=> "'{$name}' => 'boolean',",
            'json'           => "'{$name}' => 'array',",
            'date'           => "'{$name}' => 'date',",
            'datetime'       => "'{$name}' => 'datetime',",
            default          => null,
        };
    }
}
