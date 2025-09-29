<?php

namespace CoreSuit\MigrationGen\Injectors;

use Illuminate\Filesystem\Filesystem;

class ProviderInjector
{
    public function __construct(
        private Filesystem $filesystem
    ) {}

    public function inject(
        string $providerPath,
        array $usesFqcn,
        string $bindLine,
        string $markerRaw,
        string $providerClassName
    ): void {
        $markerComment = "// {$markerRaw}";
        $namespace     = 'App\\Providers';

        // 1) Se não existir, cria skeleton
        if (!$this->filesystem->exists($providerPath)) {
            $skeleton = <<<PHP
<?php

namespace {$namespace};

use Illuminate\\Support\\ServiceProvider;

class {$providerClassName} extends ServiceProvider
{
    public function register(): void
    {
        {$markerComment}
    }

    public function boot(): void {}
}

PHP;
            $this->filesystem->put($providerPath, $skeleton);
        }

        // 2) Normaliza: garante 1 namespace, com quebra de linha certa
        $contents = $this->filesystem->get($providerPath);
        $contents = $this->normalizeNamespace($contents, $namespace);

        // 3) Garante o marcador comentado (retrocompat.)
        if (str_contains($contents, $markerRaw) && !str_contains($contents, $markerComment)) {
            $contents = str_replace($markerRaw, $markerComment, $contents);
        }

        // 4) Insere "use ..." após o namespace (sem duplicar)
        foreach ($usesFqcn as $fq) {
            $useLine = "use {$fq};";
            if (!preg_match('/^use\s+' . preg_quote($fq, '/') . '\s*;\s*$/m', $contents)) {
                $contents = $this->insertUseAfterNamespace($contents, $useLine);
            }
        }

        // 5) Garante register(): void com o marcador dentro
        if (!preg_match('/public\s+function\s+register\s*\(\s*\)\s*:\s*void\s*\{/', $contents)) {
            if (preg_match('/public\s+function\s+boot\s*\(\s*\)\s*:\s*void\s*\{/', $contents)) {
                $contents = preg_replace(
                    '/public\s+function\s+boot\s*\(\s*\)\s*:\s*void\s*\{/',
                    "public function register(): void\n    {\n        {$markerComment}\n    }\n\n    public function boot(): void\n    {",
                    $contents,
                    1
                );
            } else {
                $contents = preg_replace(
                    '/\}\s*$/',
                    "    public function register(): void\n    {\n        {$markerComment}\n    }\n}\n",
                    $contents,
                    1
                );
            }
        } else {
            // garante o marcador dentro do corpo atual do register()
            $contents = preg_replace_callback(
                '/public\s+function\s+register\s*\(\s*\)\s*:\s*void\s*\{\s*(.*?)\}/s',
                function ($m) use ($markerComment) {
                    $body = $m[1];
                    if (!str_contains($body, $markerComment)) {
                        $body = "        {$markerComment}\n" . ltrim($body);
                    }
                    return "public function register(): void\n    {\n{$body}}";
                },
                $contents,
                1
            );
        }

        // 6) Injeta o bind (sem duplicar), preferencialmente após o marcador
        if (!str_contains($contents, $bindLine)) {
            // após o marcador // [coresuit_providers]
            $patternMarker = '/(\/\/\s*' . preg_quote($markerRaw, '/') . '\s*(?:\r?\n))/';
            if (preg_match($patternMarker, $contents)) {
                $contents = preg_replace(
                    $patternMarker,
                    "$1        {$bindLine}\n",
                    $contents,
                    1
                );
            } else {
                // fallback: no início do corpo de register()
                $contents = preg_replace(
                    '/public\s+function\s+register\s*\(\s*\)\s*:\s*void\s*\{\s*/',
                    "public function register(): void\n    {\n        {$bindLine}\n        ",
                    $contents,
                    1
                );
            }
        }

        $this->filesystem->put($providerPath, $contents);
    }

    /**
     * Garante header correto:
     *   <?php
     *   [declare(...);]
     *   namespace {ns};
     *
     * Remove namespaces antigos e reinsere uses em posição correta.
     */
    private function normalizeNamespace(string $contents, string $namespace): string
    {
        // começa com <?php + quebra de linha
        if (!preg_match('/^\s*<\?php\b/', $contents)) {
            $contents = "<?php\n" . ltrim($contents);
        } else {
            $contents = preg_replace('/^\s*<\?php\b[^\n]*\R?/', "<?php\n", $contents, 1);
        }

        // preserva declare(...) do topo (se houver)
        $declare = '';
        if (preg_match('/^\s*<\?php\s*(declare\s*\(.*?\)\s*;\s*)/s', $contents, $m)) {
            $declare = $m[1];
            $contents = preg_replace('/^\s*<\?php\s*declare\s*\(.*?\)\s*;\s*/s', "<?php\n", $contents, 1);
        }

        // remove namespaces existentes
        $contents = preg_replace('/^\s*namespace\s+[^;]+;\s*$/m', '', $contents);

        // remove uses do topo para reinserir depois
        $uses = [];
        if (preg_match_all('/^\s*use\s+[^;]+;\s*$/m', $contents, $uMatches)) {
            $uses = array_map('trim', $uMatches[0]);
            $contents = preg_replace('/^\s*use\s+[^;]+;\s*$/m', '', $contents);
        }

        // monta header
        $header = "<?php\n";
        if ($declare !== '') {
            $header .= rtrim($declare) . "\n";
        }
        $header .= "namespace {$namespace};\n\n";

        // coloca header no topo (substitui cabeçalhos anteriores)
        if (preg_match('/^(?:<\?php.*?\n)?(?:declare\s*\(.*?\)\s*;\s*\n)?(?:namespace\s+[^;]+;\s*\n+)?/s', $contents)) {
            $contents = preg_replace(
                '/^(?:<\?php.*?\n)?(?:declare\s*\(.*?\)\s*;\s*\n)?(?:namespace\s+[^;]+;\s*\n+)?/s',
                $header,
                $contents,
                1
            );
        } else {
            $contents = $header . ltrim($contents);
        }

        // reinsere uses únicos, ordenados, após o namespace
        if (!empty($uses)) {
            $uses = array_values(array_unique($uses));
            foreach ($uses as $line) {
                $contents = $this->insertUseAfterNamespace($contents, $line);
            }
        }

        return $contents;
    }

    /**
     * Insere uma linha "use ..." imediatamente após o namespace e
     * após quaisquer outros "use" existentes (sem duplicar).
     */
    private function insertUseAfterNamespace(string $contents, string $useLine): string
    {
        if (preg_match('/^' . preg_quote($useLine, '/') . '\s*$/m', $contents)) {
            return $contents; // já existe idêntico
        }

        if (preg_match('/^\s*namespace\s+[^\;]+;\s*$/m', $contents, $nsMatch, PREG_OFFSET_CAPTURE)) {
            $nsEnd = $nsMatch[0][1] + strlen($nsMatch[0][0]);

            // bloco de uses atual logo após namespace
            if (preg_match('/\G(\s*(?:use\s+[^;]+;\s*\R)*)/A', substr($contents, $nsEnd), $useBlockMatch)) {
                $insertPos = $nsEnd + strlen($useBlockMatch[1] ?? '');
                $before    = substr($contents, 0, $insertPos);
                $after     = substr($contents, $insertPos);

                return rtrim($before, "\r\n") . "\n{$useLine}\n" . ltrim($after, "\r\n");
            }

            // não havia bloco de uses — cria um
            return substr($contents, 0, $nsEnd) . "\n{$useLine}\n\n" . ltrim(substr($contents, $nsEnd));
        }

        // fallback improvável: coloca após <?php
        return preg_replace('/^\s*<\?php\s*\R*/', "<?php\n{$useLine}\n\n", $contents, 1);
    }
}
