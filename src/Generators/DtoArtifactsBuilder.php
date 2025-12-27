<?php

namespace CoreSuit\MigrationGen\Generators;

use Illuminate\Support\Str;

final class DtoArtifactsBuilder
{
    public function build(string $className, array $fields): array
    {
        // DTO props
        $ctorProps = [];
        $fromValidated = [];
        $toArray = [];

        // Mapper args
        $listArgs = [];
        $detailArgs = [];

        // Regras:
        // - create/update: incluem campos do input (os $fields)
        // - listItem: id + campos do input (sem timestamps)
        // - detail: id + campos do input + createdAt/updatedAt

        foreach ($fields as $f) {
            $name = $f['name'];
            $type = $this->phpType($f['type'], $f['required']);

            // ctor prop linha (ex: "public string $description,")
            $ctorProps[] = "        public {$type} \${$name},";

            // from validated (ex: "description: (string) $data['description'],")
            $fromValidated[] = "            {$name}: {$this->castExpr($f['type'], "\$data['{$name}']")},";

            // to array (ex: "'description' => $this->description,")
            $toArray[] = "            '{$name}' => \$this->{$name},";

            // mapper -> list/detail
            $listArgs[] = "            {$this->dtoPropName($name)}: {$this->modelValueExpr($f['type'], $name)},";

            $detailArgs[] = "            {$this->dtoPropName($name)}: {$this->modelValueExpr($f['type'], $name)},";
        }

        // listItem sempre tem id
        array_unshift($listArgs, "            id: (int) \$model->id,");

        // detail sempre tem id + createdAt/updatedAt
        array_unshift($detailArgs, "            id: (int) \$model->id,");
        $detailArgs[] = "            createdAt: \$model->created_at?->toISOString(),";
        $detailArgs[] = "            updatedAt: \$model->updated_at?->toISOString(),";

        // DTO listItem ctor props e toArray (id + fields)
        $listCtor = ["        public int \$id,"];
        $listToArray = ["            'id' => \$this->id,"];

        foreach ($fields as $f) {
            $name = $f['name'];
            $type = $this->phpType($f['type'], true); // listItem geralmente retorna valor (pode ser null, mas ok manter required=true aqui)
            $listCtor[] = "        public {$type} \${$name},";
            $listToArray[] = "            '{$name}' => \$this->{$name},";
        }

        // DTO detail ctor props e toArray (id + fields + createdAt/updatedAt)
        $detailCtor = ["        public int \$id,"];
        $detailToArray = ["            'id' => \$this->id,"];

        foreach ($fields as $f) {
            $name = $f['name'];
            $type = $this->phpType($f['type'], true);
            $detailCtor[] = "        public {$type} \${$name},";
            $detailToArray[] = "            '{$name}' => \$this->{$name},";
        }

        $detailCtor[] = "        public ?string \$createdAt,";
        $detailCtor[] = "        public ?string \$updatedAt,";
        $detailToArray[] = "            'createdAt' => \$this->createdAt,";
        $detailToArray[] = "            'updatedAt' => \$this->updatedAt,";

        return [
            'create' => [
                'ctor_props' => $ctorProps,
                'from_validated_named_args' => $fromValidated,
                'to_array' => $toArray,
            ],
            'update' => [
                'ctor_props' => $ctorProps,
                'from_validated_named_args' => $fromValidated,
                'to_array' => $toArray,
            ],
            'list_item' => [
                'ctor_props' => $listCtor,
                'to_array' => $listToArray,
                'named_args' => $listArgs,
            ],
            'detail' => [
                'ctor_props' => $detailCtor,
                'to_array' => $detailToArray,
                'named_args' => $detailArgs,
            ],
        ];
    }

    private function phpType(string $type, bool $required): string
    {
        $base = match ($type) {
            'int', 'integer', 'bigint' => 'int',
            'decimal' => 'float',
            'bool', 'boolean' => 'bool',
            'json' => 'array',
            'date', 'datetime' => 'string', // DTO sempre serializa
            default => 'string',
        };

        return $required ? $base : "?{$base}";
    }

    private function castExpr(string $type, string $expr): string
    {
        return match ($type) {
            'int', 'integer', 'bigint' => "(int) {$expr}",
            'decimal' => "(float) {$expr}",
            'bool', 'boolean' => "(bool) {$expr}",
            default => "(string) {$expr}",
        };
    }

    private function modelValueExpr(string $type, string $name): string
    {
        // Para decimal, Eloquent pode vir string -> float
        return match ($type) {
            'int', 'integer', 'bigint' => "(int) \$model->{$name}",
            'decimal' => "(float) \$model->{$name}",
            'bool', 'boolean' => "(bool) \$model->{$name}",
            default => "(string) \$model->{$name}",
        };
    }

    private function dtoPropName(string $fieldName): string
    {
        // mantém o mesmo nome (snake) porque seus DTOs usam snake também
        // se quiser camelCase aqui, você muda essa função
        return $fieldName;
    }
}
