<?php

namespace CoreSuit\MigrationGen\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeInit extends Command
{
    protected $signature = 'coresuit:make-migration {action=init}
                            {--force : Sobrescrever arquivos existentes}';

    protected $description = 'Gera bases (Repository/Service/Controller/Requests/DTO/OpenAPI), registra providers e exibe checklist de instalação de terceiros.';

    public function handle(Filesystem $fs): int
    {
        $action = (string) $this->argument('action');
        if ($action !== 'init') {
            $this->error('Uso: php artisan coresuit:make-migration init');
            return 1;
        }

        // 1) Arquivos base (+ Providers + OpenAPI + config l5-swagger)
        $targets = [
            // Bases
            'app/Repositories/Shared/BaseRepository.php'     => 'base_repository.stub',
            'app/Repositories/Contracts/IBaseRepository.php' => 'base_irepository.stub',
            'app/Services/Shared/BaseService.php'            => 'base_service.stub',
            'app/Services/Shared/IBaseService.php'           => 'base_iservice.stub',
            'app/DTOs/Shared/IndexQueryDTO.php'              => 'index_query_dto.stub',
            'app/Http/Requests/Shared/BaseIndexRequest.php'  => 'base_index_request.stub',
            'app/Http/Requests/Shared/IndexRequest.php'      => 'index_request.stub',
            'app/Http/Controllers/Shared/BaseController.php' => 'base_controller.stub',
            'app/DTOs/Shared/PageDTO.php'                    => 'dto_page.stub',
            'app/Mappers/Contracts/ICrudMapper.php'          => 'mapper_contract.stub',



            // OpenAPI (@OA\OpenApi + Info/Security globais)
            'app/OpenApi/OpenApi.php'                        => 'openapi_boot.stub',

            // Providers vazios com marcador [coresuit_providers]
            'app/Providers/RepositoryServiceProvider.php'    => 'repository_provider.stub',
            'app/Providers/ServicesServiceProvider.php'      => 'services_provider.stub',

            // Config do L5 Swagger (com fallback seguro p/ ausência do pacote)
            'config/l5-swagger.php'                          => 'l5_swagger_config.stub',
        ];

        foreach ($targets as $dest => $stubName) {
            $stubPath = $this->resolveStubPath($fs, $stubName);
            $this->publish($fs, base_path($dest), $fs->get($stubPath), (bool) $this->option('force'));
        }

        // 2) Registrar providers (bootstrap/providers.php OU config/app.php)
        $this->ensureProvidersRegistered($fs, [
            'App\\Providers\\RepositoryServiceProvider',
            'App\\Providers\\ServicesServiceProvider',
        ]);

        // 3) Garantir diretórios e artefatos mínimos p/ Swagger
        $this->ensureDir($fs, base_path('app/Http/Controllers/System'));
        $this->ensureDir($fs, storage_path('api-docs'));
        $this->ensureHealthController($fs);
        $this->ensureHealthRoute($fs);

        // 4) Flag de init (permite travar o MakeEntity se não rodar init antes)
        $this->writeInitFlag($fs);

        $this->info('✔ Bases geradas e providers registrados.');
        $this->line('');

        // 5) Checklist de próximos passos (sem executar nada automaticamente)
        $this->line('Agora, para funcionar 100%, instale/configure os pacotes de terceiros manualmente:');
        $this->line('');
        $this->line('1) (Opcional) API stack do Laravel (Sanctum):');
        $this->line('   php artisan install:api');
        $this->line('   # Se seu DB estiver configurado, responda "yes" para rodar as migrações;');
        $this->line('   # Depois, adicione o trait Laravel\\Sanctum\\HasApiTokens no seu App\\Models\\User.');
        $this->line('');
        $this->line('2) L5 Swagger (documentação OpenAPI):');
        $this->line('   composer require darkaonline/l5-swagger');
        $this->line('   php artisan package:discover --ansi');
        $this->line('   # (Se quiser publicar views/config do pacote: php artisan vendor:publish --provider="L5Swagger\\L5SwaggerServiceProvider")');
        $this->line('   php artisan l5-swagger:generate');
        $this->line('   # A doc ficará disponível (por padrão) em: /api/documentation');
        $this->line('');
        $this->line('3) Teste rápido da API gerada pelo init:');
        $this->line('   curl ' . rtrim(config('app.url') ?? 'http://localhost', '/') . '/api/health');
        $this->line('');
        $this->info('✅ Init finalizado. Siga o checklist acima quando quiser ativar as integrações.');

        return 0;
    }

    // ---------- Helpers de arquivo / stub ----------

    private function resolveStubPath(Filesystem $fs, string $file): string
    {
        $published = base_path('stubs/coresuit/' . $file);
        if ($fs->exists($published)) {
            return $published;
        }
        return __DIR__ . '/../stubs/' . $file;
    }

    private function publish(Filesystem $fs, string $dest, string $contents, bool $force): void
    {
        $dir = dirname($dest);
        if (!$fs->isDirectory($dir)) {
            $fs->makeDirectory($dir, 0755, true);
        }
        if ($fs->exists($dest) && !$force) {
            $this->warn("• Já existe: {$dest} (use --force para sobrescrever)");
            return;
        }
        $fs->put($dest, $contents);
        $this->line("• Criado: {$dest}");
    }

    private function ensureDir(Filesystem $fs, string $absPath): void
    {
        if (!$fs->isDirectory($absPath)) {
            $fs->makeDirectory($absPath, 0755, true);
            @file_put_contents($absPath . '/.gitkeep', '');
        }
    }

    private function writeInitFlag(Filesystem $fs): void
    {
        $flagPath = base_path('bootstrap/cache/coresuit_init.flag');
        if (!$fs->isDirectory(dirname($flagPath))) {
            $fs->makeDirectory(dirname($flagPath), 0755, true);
        }
        $fs->put($flagPath, (string) time());
        $this->line('• Flag de init criado em: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $flagPath));
    }

    // Cria um controller /health com anotação @OA\Get se estiver faltando
    private function ensureHealthController(Filesystem $fs): void
    {
        $path = base_path('app/Http/Controllers/System/HealthController.php');
        if ($fs->exists($path)) {
            return;
        }

        // tenta via stub; se não existir, usa fallback embutido
        $stubPath = $this->resolveStubPath($fs, 'health_controller.stub');
        if ($fs->exists($stubPath)) {
            $this->publish($fs, $path, $fs->get($stubPath), (bool) $this->option('force'));
            return;
        }

        $fallback = <<<'PHP'
<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *   name="Health",
 *   description="Endpoint de verificação"
 * )
 */
class HealthController extends Controller
{
    /**
     * @OA\Get(
     *   path="/health",
     *   tags={"Health"},
     *   summary="Health check",
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'time'   => now()->toISOString(),
        ]);
    }
}
PHP;

        $this->publish($fs, $path, $fallback, (bool) $this->option('force'));
    }

    // Injeta a rota /health no routes/api.php (idempotente)
    private function ensureHealthRoute(Filesystem $fs): void
    {
        $routesPath = base_path('routes/api.php');

        if (!$fs->exists($routesPath)) {
            $fs->put($routesPath, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n");
        }

        $content = $fs->get($routesPath);

        // Garante o use Route;
        if (!str_contains($content, 'use Illuminate\\Support\\Facades\\Route;')) {
            $content = preg_replace('/<\?php\s*/', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n", $content, 1);
        }

        // Garante a rota
        $line = "Route::get('health', \\App\\Http\\Controllers\\System\\HealthController::class);";
        if (!str_contains($content, $line)) {
            $content = rtrim($content) . "\n" . $line . "\n";
            $fs->put($routesPath, $content);
            $this->line('• Rota /health adicionada em: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $routesPath));
        }
    }

    // ---------- Providers ----------

    private function ensureProvidersRegistered(Filesystem $fs, array $providersFqcn): void
    {
        // Laravel 11/12: bootstrap/providers.php (array retornado)
        $path = base_path('bootstrap/providers.php');
        if (!$fs->exists($path)) {
            // fallback: config/app.php (bloco 'providers' => [ ... ])
            $path = base_path('config/app.php');
        }

        if (!$fs->exists($path)) {
            $this->warn('Arquivo de providers não encontrado (bootstrap/providers.php nem config/app.php). Pulei o registro automático.');
            return;
        }

        $contents = $fs->get($path);
        $missing  = [];

        foreach ($providersFqcn as $fqcn) {
            if (!str_contains($contents, $fqcn . '::class')) {
                $missing[] = $fqcn;
            }
        }

        if (empty($missing)) {
            $this->line('• Providers já estavam registrados.');
            return;
        }

        $insertion = '';
        foreach ($missing as $fqcn) {
            $insertion .= "    {$fqcn}::class," . PHP_EOL;
        }

        // Caso "return [ ... ];"
        if (preg_match('/return\s*\[\s*(.*)\s*\]\s*;\s*$/s', $contents)) {
            $contents = preg_replace('/\]\s*;\s*$/', $insertion . '];' . PHP_EOL, $contents, 1);
            $fs->put($path, $contents);
            $this->line('• Providers injetados em: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
            return;
        }

        // Caso "providers" => [ ... ]
        if (preg_match('/([\'"]providers[\'"]\s*=>\s*\[)(.*?)(\])/s', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $posClose = $m[3][1];
            $contents = substr_replace($contents, PHP_EOL . $insertion, $posClose, 0);
            $fs->put($path, $contents);
            $this->line('• Providers injetados em: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
            return;
        }

        $this->warn('Não consegui detectar o bloco de providers. Adicione manualmente:');
        foreach ($missing as $fqcn) {
            $this->line('  - ' . $fqcn . '::class');
        }
    }
}
