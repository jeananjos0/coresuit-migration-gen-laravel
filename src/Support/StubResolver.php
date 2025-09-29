<?php

namespace CoreSuit\MigrationGen\Support;

use Illuminate\Filesystem\Filesystem;

class StubResolver
{
    public function __construct(private Filesystem $filesystem) {}

    /**
     * Resolve na ordem:
     * 1) Caminho passado via --stub-<tipo>
     * 2) stubs publicados em base_path('stubs/coresuit/<tipo>.stub')
     * 3) stubs internos do pacote em __DIR__ . '/../stubs/<tipo>.stub'
     */
    public function resolve(string $optionPathOrNull, string $type): string
    {
        if ($optionPathOrNull && $this->filesystem->exists($optionPathOrNull)) {
            return $optionPathOrNull;
        }

        $publishedPath = \base_path("stubs/coresuit/{$type}.stub");
        if ($this->filesystem->exists($publishedPath)) {
            return $publishedPath;
        }

        return __DIR__ . "/../stubs/{$type}.stub";
    }
}
