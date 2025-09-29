<?php

namespace CoreSuit\MigrationGen\Generators;

use Illuminate\Support\Str;

class RequestArtifactsBuilder
{
    public function build(array $fields): array
    {
        $storeRules = [];
        $updateRules = [];
        $filterRules = [];
        $stringFilterFields = [];
        $storeSanitizers = [];
        $updateSanitizers = [];
        $storeMessages = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];
            $isRequired = $field['required'];

            $baseRules = match ($type) {
                'string'                   => ["'string'", "'max:255'"],
                'text'                     => ["'string'"],
                'int', 'integer', 'bigint' => ["'integer'"],
                'decimal'                  => ["'numeric'"],
                'bool', 'boolean'          => ["'boolean'"],
                'date', 'datetime'         => ["'date'"],
                'json'                     => ["'array'"],
                default                    => ["'string'"],
            };

            if (Str::endsWith($name, '_id')) {
                $referencedEntity = Str::of($name)->beforeLast('_id')->studly()->toString();
                $referencedTable  = Str::of($referencedEntity)->snake()->plural()->toString();
                if (!in_array("'integer'", $baseRules, true)) {
                    $baseRules[] = "'integer'";
                }
                $baseRules[] = "'exists:{$referencedTable},id'";
            }

            $requiredPart = $isRequired ? ["'required'"] : ["'sometimes'"];
            $rulesArray = array_merge($requiredPart, $baseRules);
            $ruleLine = "'{$name}' => [" . implode(', ', $rulesArray) . "],";

            $storeRules[] = $ruleLine;
            $updateRules[] = $ruleLine;

            if (in_array($type, ['string', 'text'], true)) {
                $storeSanitizers[]  = "        if (\$this->has('{$name}')) { \$this->merge(['{$name}' => trim((string) \$this->input('{$name}'))]); }";
                $updateSanitizers[] = "        if (\$this->has('{$name}')) { \$this->merge(['{$name}' => trim((string) \$this->input('{$name}'))]); }";
            }
            if (in_array($type, ['bool', 'boolean'], true)) {
                $line = "        if (\$this->has('{$name}')) { \$this->merge(['{$name}' => filter_var(\$this->input('{$name}'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)]); }";
                $storeSanitizers[]  = $line;
                $updateSanitizers[] = $line;
            }

            if (in_array($type, ['string', 'text'], true)) {
                $filterRules[] = "            'filters.{$name}' => ['sometimes'],";
                $filterRules[] = "            'filters.{$name}.like' => ['sometimes','string','max:255'],";
                $stringFilterFields[] = "            '{$name}',";
            } elseif (in_array($type, ['int', 'integer', 'bigint'], true) || Str::endsWith($name, '_id')) {
                $filterRules[] = "            'filters.{$name}' => ['sometimes','integer'],";
            } elseif ($type === 'decimal') {
                $filterRules[] = "            'filters.{$name}' => ['sometimes','numeric'],";
            } elseif (in_array($type, ['date', 'datetime'], true)) {
                $filterRules[] = "            'filters.{$name}' => ['sometimes','date'],";
            } elseif ($type === 'bool' || $type === 'boolean') {
                $filterRules[] = "            'filters.{$name}' => ['sometimes','boolean'],";
            } else {
                $filterRules[] = "            'filters.{$name}' => ['sometimes'],";
            }

            if ($type === 'string') {
                if ($isRequired) {
                    $storeMessages[] = "            '{$name}.required' => 'O campo {$name} é obrigatório.',";
                }
                $storeMessages[] = "            '{$name}.max' => 'O campo {$name} pode ter no máximo 255 caracteres.',";
            }
        }

        if (empty($storeSanitizers))  { $storeSanitizers[]  = "        //"; }
        if (empty($updateSanitizers)) { $updateSanitizers[] = "        //"; }
        if (empty($storeMessages))    { $storeMessages[]    = "            //"; }

        return [$storeRules, $updateRules, $filterRules, $stringFilterFields, $storeSanitizers, $updateSanitizers, $storeMessages];
    }
}
