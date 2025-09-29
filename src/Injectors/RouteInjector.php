<?php

namespace CoreSuit\MigrationGen\Injectors;

use Illuminate\Filesystem\Filesystem;
use CoreSuit\MigrationGen\Support\UseLineInserter;

class RouteInjector
{
    public function __construct(
        private Filesystem $filesystem,
        private UseLineInserter $useLineInserter
    ) {}

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

        if (!$this->filesystem->exists($routesPath)) {
            $skeleton = <<<PHP
<?php

use Illuminate\\Support\\Facades\\Route;

Route::middleware('{$middleware}')->group(function () {
    {$markerComment}
});
PHP;
            $this->filesystem->put($routesPath, $skeleton);
        }

        $contents = $this->filesystem->get($routesPath);

        if (strpos($contents, $markerRaw) !== false && strpos($contents, $markerComment) === false) {
            $contents = str_replace($markerRaw, $markerComment, $contents);
        }

        if (!str_contains($contents, 'use Illuminate\\Support\\Facades\\Route;')) {
            $contents = $this->useLineInserter->insertUseLineSmart($contents, 'use Illuminate\\Support\\Facades\\Route;');
        }

        $useController = "use {$controllerFqcn};";
        if (!str_contains($contents, $useController)) {
            $contents = $this->useLineInserter->insertUseLineSmart($contents, $useController);
        }

        $controllerClassPattern = preg_quote($controllerShort, '/');
        $prefixPattern = preg_quote($routePrefix, '/');
        $alreadyThere = (bool) preg_match(
            "/Route::prefix\\(\\s*['\"]{$prefixPattern}['\"]\\s*\\)\\s*->controller\\(\\s*{$controllerClassPattern}::class\\s*\\)/",
            $contents
        );
        if ($alreadyThere) {
            $this->filesystem->put($routesPath, $contents);
            return;
        }

        if (!str_contains($contents, $markerComment)) {
            $contents .= "\n\nRoute::middleware('{$middleware}')->group(function () {\n    {$markerComment}\n});\n";
        }

        $indentedBlock = preg_replace('/^/m', '    ', $routeBlock) . "\n";
        $contents = str_replace($markerComment, $indentedBlock . '    ' . $markerComment, $contents);

        $this->filesystem->put($routesPath, $contents);
    }
}
