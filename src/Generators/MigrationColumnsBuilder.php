<?php

namespace CoreSuit\MigrationGen\Generators;

use Illuminate\Support\Str;

class MigrationColumnsBuilder
{
    public function buildColumnsAndForeignKeys(array $fields): array
    {
        $columns = [];
        $foreignKeys = [];

        foreach ($fields as $field) {
            $columns[] = "                " . $this->columnForMigration($field['name'], $field['type'], $field['required']);

            if (Str::endsWith($field['name'], '_id')) {
                $referencedEntity = Str::of($field['name'])->beforeLast('_id')->studly()->toString();
                $referencedTable  = Str::of($referencedEntity)->snake()->plural()->toString();
                $foreignKeys[] = "                \$table->foreign('{$field['name']}')->references('id')->on('{$referencedTable}');";
            }
        }

        return [$columns, $foreignKeys];
    }

    public function columnForMigration(string $name, string $type, bool $required): string
    {
        $nullable = $required ? '' : '->nullable()';

        return match ($type) {
            'string'            => "\$table->string('{$name}'){$nullable};",
            'text'              => "\$table->text('{$name}'){$nullable};",
            'int', 'integer'    => "\$table->unsignedInteger('{$name}'){$nullable};",
            'bigint'            => "\$table->unsignedBigInteger('{$name}'){$nullable};",
            'decimal'           => "\$table->decimal('{$name}', 18, 2){$nullable};",
            'bool', 'boolean'   => "\$table->boolean('{$name}')" . ($required ? '' : "->default(false)") . ";",
            'date'              => "\$table->date('{$name}'){$nullable};",
            'datetime'          => "\$table->dateTime('{$name}'){$nullable};",
            'json'              => "\$table->json('{$name}'){$nullable};",
            default             => "\$table->string('{$name}'){$nullable};",
        };
    }
}
