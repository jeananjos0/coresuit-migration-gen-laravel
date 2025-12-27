<?php

namespace CoreSuit\MigrationGen\Injectors;

use Illuminate\Filesystem\Filesystem;

final class RouteInjector
{
    public function __construct(private Filesystem $fs) {}

    public function inject(
        string $routesPath,
        string $controllerFqcn,
        string $controllerShort,
        string $routeBlock,
        string $markerRaw,
        string $middleware,
        string $routePrefix
    ): void {
        $markerComment = "// {$markerRaw}";

        // 1) Se não existir, cria um esqueleto consistente
        if (!$this->fs->exists($routesPath)) {
            $this->fs->put($routesPath, $this->defaultSkeleton($middleware, $markerComment));
        }

        $contents = $this->fs->get($routesPath);

        // 2) Normaliza EOL (evita bug CRLF vs LF e replace falho)
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        // 3) Garante "<?php" no topo
        if (!preg_match('/^\s*<\?php\b/', $contents)) {
            $contents = "<?php\n\n" . ltrim($contents);
        }

        // 4) Garante "use Illuminate\Support\Facades\Route;" no topo (bloco de uses)
        $contents = $this->ensureUseAtTop($contents, "use Illuminate\\Support\\Facades\\Route;");

        // 5) Garante "use Controller" no topo (bloco de uses)
        $contents = $this->ensureUseAtTop($contents, "use {$controllerFqcn};");

        // 6) Garante marker comentado (se alguém colocou sem //)
        if (str_contains($contents, $markerRaw) && !str_contains($contents, $markerComment)) {
            $contents = str_replace($markerRaw, $markerComment, $contents);
        }

        // 7) Se não tiver o marker em lugar nenhum, cria group padrão (idempotente)
        if (!str_contains($contents, $markerComment)) {
            $contents = rtrim($contents) . "\n\n" . $this->defaultGroup($middleware, $markerComment) . "\n";
        }

        // 8) Evita duplicar rota do prefix/controller
        $prefixPattern = preg_quote($routePrefix, '/');
        $controllerPattern = preg_quote($controllerShort, '/');

        $alreadyThere = (bool) preg_match(
            "/Route::prefix\\(\\s*['\"]{$prefixPattern}['\"]\\s*\\)\\s*->controller\\(\\s*{$controllerPattern}::class\\s*\\)/",
            $contents
        );

        if ($alreadyThere) {
            // volta EOL original do sistema (opcional)
            $this->fs->put($routesPath, $this->toSystemEol($contents));
            return;
        }

        // 9) Injeta SEMPRE acima do marker, com indent fixo 4 espaços
        $routeBlock = rtrim($routeBlock) . "\n";
        $indented = preg_replace('/^/m', '    ', $routeBlock);

        $contents = preg_replace(
            '/^([ \t]*)\/\/\s*' . preg_quote($markerRaw, '/') . '\s*$/m',
            $indented . "    {$markerComment}",
            $contents,
            1
        );

        // 10) Se por algum motivo não substituiu (marker torto), faz fallback seguro:
        if (!str_contains($contents, $controllerShort . '::class')) {
            $contents = str_replace($markerComment, $indented . "    {$markerComment}", $contents);
        }

        $this->fs->put($routesPath, $this->toSystemEol($contents));
    }

    private function defaultSkeleton(string $middleware, string $markerComment): string
    {
        return "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n" .
            "Route::middleware('{$middleware}')->group(function () {\n" .
            "    {$markerComment}\n" .
            "});\n";
    }

    private function defaultGroup(string $middleware, string $markerComment): string
    {
        return "Route::middleware('{$middleware}')->group(function () {\n" .
            "    {$markerComment}\n" .
            "});";
    }

    /**
     * Insere uma linha "use ..." SEMPRE no topo, abaixo de "<?php"
     * e abaixo do bloco de "use" já existente.
     * Nunca insere no meio de uma palavra (que gerava o "r;" e quebrava linhas).
     */
    private function ensureUseAtTop(string $contents, string $useLine): string
    {
        if (preg_match('/^' . preg_quote($useLine, '/') . '\s*$/m', $contents)) {
            return $contents;
        }

        // acha o topo após "<?php"
        if (!preg_match('/^\s*<\?php\s*/', $contents, $m)) {
            return $useLine . "\n" . $contents;
        }

        // posição logo após "<?php" + possíveis quebras
        $contents = preg_replace('/^\s*<\?php\s*/', "<?php\n\n", $contents, 1);

        // pega bloco de uses já existente logo no topo
        if (preg_match('/^\<\?php\s*\n+(?<uses>(?:use\s+[^;]+;\s*\n+)*)/m', $contents, $mm)) {
            $uses = $mm['uses'] ?? '';
            $insert = rtrim($uses, "\n") . "\n" . $useLine . ";\n\n";
            // substitui o bloco de uses do topo por (uses atuais + novo use)
            $contents = preg_replace(
                '/^\<\?php\s*\n+(?:use\s+[^;]+;\s*\n+)*/m',
                "<?php\n\n" . $insert,
                $contents,
                1
            );
            return $contents;
        }

        // se não havia nenhum use no topo, cria
        return preg_replace('/^\<\?php\s*\n+/m', "<?php\n\n{$useLine};\n\n", $contents, 1);
    }

    private function toSystemEol(string $contents): string
    {
        // se quiser manter LF sempre, pode retornar $contents direto.
        // aqui vou manter LF (mais seguro pra git)
        return rtrim($contents) . "\n";
    }
}
