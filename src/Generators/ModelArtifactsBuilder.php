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

        $allowedSorts = ['id', 'created_at'];
        $allowedFilters = [];
        $allowedRelations = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];

            $fillable[] = "'{$name}',";
            $phpDoc[] = " * @property {$this->phpDocType($type)}" . ($field['required'] ? '' : '|null') . " \${$name}";

            // sorts: id + todos os campos + created_at
            $allowedSorts[] = $name;

            // filters: todos os campos (você pode refinar se quiser)
            $allowedFilters[] = $name;

            if ($cast = $this->castFor($type, $name)) {
                $casts[] = $cast;
            }

            if (Str::endsWith($name, '_id')) {
                $relatedStudly = Str::of($name)->beforeLast('_id')->studly()->toString();
                $method = Str::of($relatedStudly)->camel()->toString();

                $allowedRelations[] = $method;

                $relations[] =
                    "    public function {$method}()\n" .
                    "    {\n" .
                    "        return \$this->belongsTo(\\App\\Models\\{$relatedStudly}::class);\n" .
                    "    }";
            }
        }

        // unique + defaults
        $allowedSorts = array_values(array_unique($allowedSorts));
        $allowedFilters = array_values(array_unique($allowedFilters));
        $allowedRelations = array_values(array_unique($allowedRelations));

        if (empty($casts)) {
            $casts[] = "//";
        }
        if (empty($relations)) {
            $relations[] = "//";
        }

        return [$fillable, $casts, $relations, $phpDoc, $allowedSorts, $allowedFilters, $allowedRelations];
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
            'bool', 'boolean' => "'{$name}' => 'boolean',",
            'json'           => "'{$name}' => 'array',",
            'date'           => "'{$name}' => 'date',",
            'datetime'       => "'{$name}' => 'datetime',",
            default          => null,
        };
    }
}
