<?php

namespace CoreSuit\MigrationGen\Generators;

use Illuminate\Support\Str;

class ControllerArtifactsBuilder
{
    public function build(array $fields): array
    {
        $withRelations = [];
        $filtersParameters = [];
        $storeProperties = [];
        $updateProperties = [];
        $storeRequired = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];
            $isRequired = $field['required'];

            if (Str::endsWith($name, '_id')) {
                $withRelations[] = Str::of($name)->beforeLast('_id')->camel()->toString();
            }

            [$schemaType, $format] = match ($type) {
                'int', 'integer', 'bigint' => ['integer', null],
                'decimal'                  => ['number', 'double'],
                'bool', 'boolean'          => ['boolean', null],
                'date'                     => ['string', 'date'],
                'datetime'                 => ['string', 'date-time'],
                'json'                     => ['object', null],
                'text', 'string'           => ['string', null],
                default                    => ['string', null],
            };

            if (in_array($type, ['string', 'text'], true)) {
                $filtersParameters[] = "@OA\\Parameter(name=\"filters[{$name}]\", in=\"query\", @OA\\Schema(type=\"string\"), description=\"Filtro rápido por {$name} (atalho p/ like)\")";
                $filtersParameters[] = "@OA\\Parameter(name=\"filters[{$name}][like]\", in=\"query\", @OA\\Schema(type=\"string\", maxLength=255), description=\"Busca parcial (LIKE) em {$name}\")";
            } else {
                $description = "Filtro por {$name}";
                $schema = "@OA\\Schema(type=\"{$schemaType}\"" . ($format ? ", format=\"{$format}\"" : "") . ")";
                $filtersParameters[] = "@OA\\Parameter(name=\"filters[{$name}]\", in=\"query\", {$schema}, description=\"{$description}\")";
            }

            $nullableAttr = $isRequired ? '' : ', nullable=true';
            $example = match ($type) {
                'int', 'integer', 'bigint' => '1',
                'decimal'                  => '123.45',
                'bool', 'boolean'          => 'true',
                'date'                     => '"2025-01-01"',
                'datetime'                 => '"2025-01-01T12:00:00Z"',
                'json'                     => '{}',
                'text'                     => '"texto longo"',
                default                    => '"exemplo"',
            };
            $extra = ($type === 'string') ? ', maxLength=255' : '';
            $formatAttr = $format ? ", format=\"{$format}\"" : "";

            $property = "@OA\\Property(property=\"{$name}\", type=\"{$schemaType}\"{$formatAttr}{$extra}{$nullableAttr}, example={$example})";
            $storeProperties[] = $property;
            $updateProperties[] = $property;

            if ($isRequired) {
                $storeRequired[] = $name;
            }
        }

        return [$withRelations, $filtersParameters, $storeProperties, $updateProperties, $storeRequired];
    }
}
