<?php

namespace CoreSuit\MigrationGen\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use CoreSuit\MigrationGen\Support\StubResolver;
use CoreSuit\MigrationGen\Injectors\RouteInjector;
use CoreSuit\MigrationGen\Injectors\ProviderInjector;
use CoreSuit\MigrationGen\Generators\MigrationColumnsBuilder;
use CoreSuit\MigrationGen\Generators\ModelArtifactsBuilder;
use CoreSuit\MigrationGen\Generators\RequestArtifactsBuilder;
use CoreSuit\MigrationGen\Generators\ControllerArtifactsBuilder;
use CoreSuit\MigrationGen\Generators\DtoArtifactsBuilder;


class MakeEntity extends Command
{
    protected $signature = 'coresuit:make-entity
            {--name= : Nome da entidade (ex: Pedido ou TipoVariacao)}
            {--fields= : Campos ex: cliente_id:int:true,data_pedido:date:true,observacao:text:false}

            {--no-dtos : Não gerar DTOs}
            {--no-mapper : Não gerar Mapper}

            {--stub-dto_create= : Caminho customizado do stub do DTO Create}
            {--stub-dto_update= : Caminho customizado do stub do DTO Update}
            {--stub-dto_list_item= : Caminho customizado do stub do DTO ListItem}
            {--stub-dto_detail= : Caminho customizado do stub do DTO Detail}
            {--stub-mapper= : Caminho customizado do stub do Mapper}


            {--stub-migration= : Caminho customizado do stub de migration}
            {--stub-model= : Caminho customizado do stub de model}
            {--stub-repository= : Caminho customizado do stub de repository}
            {--stub-irepository= : Caminho customizado do stub de interface de repository}
            {--stub-service= : Caminho customizado do stub de service}
            {--stub-iservice= : Caminho customizado do stub de interface de service}
            {--stub-request_list= : Caminho customizado do stub de request list}
            {--stub-request_store= : Caminho customizado do stub de request store}
            {--stub-request_update= : Caminho customizado do stub de request update}
            {--stub-controller= : Caminho customizado do stub de controller}

            {--no-migration : Não gerar migration}
            {--no-model : Não gerar model}
            {--no-repo : Não gerar repository/interface}
            {--no-service : Não gerar service/interface}
            {--no-requests : Não gerar requests}
            {--no-controller : Não gerar controller}
            {--no-routes : Não gerar rotas}
            {--no-providers : Não injetar nos providers}
            {--force : Sobrescreve arquivos existentes}

            {--model-namespace=App\\Models : Namespace dos Models}

            {--repo-namespace=App\\Repositories : Namespace raiz dos repositories}
            {--contracts-namespace=App\\Repositories\\Contracts : Namespace das interfaces}
            {--repo-dir=app/Repositories : Diretório de saída dos repositories}
            {--contracts-dir=app/Repositories/Contracts : Diretório de saída das interfaces}
            {--base-repo=App\\Repositories\\Shared\\BaseRepository : Classe base do repository}
            {--base-irepo=App\\Repositories\\Contracts\\IBaseRepository : Interface base do repository}

            {--service-namespace=App\\Services : Namespace raiz dos services}
            {--service-dir=app/Services : Diretório de saída dos services}
            {--base-service=App\\Services\\Shared\\BaseService : Classe base do service}
            {--base-iservice=App\\Services\\Shared\\IBaseService : Interface base do service}

            {--requests-namespace=App\\Http\\Requests : Namespace raiz dos requests}
            {--requests-dir=app/Http/Requests : Diretório de saída dos requests}
            {--base-index-request=App\\Http\\Requests\\Shared\\BaseIndexRequest : Classe base do ListRequest}
            {--base-form-request=Illuminate\\Foundation\\Http\\FormRequest : Classe base do Store/Update}

            {--controller-namespace=App\\Http\\Controllers\\System : Namespace da controller}
            {--controller-dir=app/Http/Controllers/System : Diretório de saída da controller}
            {--base-controller=App\\Http\\Controllers\\Shared\\BaseController : Classe base da controller}
            {--route-prefix= : Prefixo de rota (default: snake do nome da entidade no singular)}
            {--tag= : Tag do Swagger (default: nome da entidade)}

            {--routes-file=routes/api.php : Arquivo onde as rotas serão injetadas}
            {--routes-middleware=auth.jwt : Middleware aplicado ao grupo de rotas}
            {--routes-marker=[router_marckenew_generates] : Marcador onde as novas rotas serão inseridas}

            {--repo-provider-file=app/Providers/RepositoryServiceProvider.php : Caminho do provider de repositórios}
            {--service-provider-file=app/Providers/ServicesServiceProvider.php : Caminho do provider de services}
            {--provider-marker=[coresuit_providers] : Marcador dentro do método register() para inserir bindings}
        ';

    protected $description = 'Gera Migration + Model + Repository/Interface/Service/Requests/Controller e injeta Rotas/Providers.';

    public function __construct(
        private StubResolver $stubResolver,
        private RouteInjector $routeInjector,
        private ProviderInjector $providerInjector,
        private MigrationColumnsBuilder $migrationBuilder,
        private ModelArtifactsBuilder $modelBuilder,
        private RequestArtifactsBuilder $requestBuilder,
        private ControllerArtifactsBuilder $controllerBuilder,
        private DtoArtifactsBuilder $dtoBuilder,

    ) {
        parent::__construct();
    }

    public function handle(Filesystem $filesystem): int
    {
        // 0) Regra: só roda se o init tiver sido executado
        if (!$this->ensureInitOrFail($filesystem)) {
            return 1;
        }

        // 1) Valida nome da entidade
        $entityName = trim((string) $this->option('name'));
        if ($entityName === '') {
            $this->error('--name é obrigatório. Ex: --name=TipoVariacao');
            return 1;
        }

        // 2) Parseia campos e nomes derivados
        $fields    = $this->parseFields((string) $this->option('fields'));
        $tableName = Str::of($entityName)->snake()->plural()->toString();
        $className = Str::studly($entityName);
        $force     = (bool) $this->option('force');

        // ---------- MIGRATION ----------
        if (!$this->option('no-migration')) {
            [$migrationColumns, $migrationForeignKeys] = $this->migrationBuilder->buildColumnsAndForeignKeys($fields);

            $migrationStubPath = $this->stubResolver->resolve((string) $this->option('stub-migration'), 'migration');
            $migrationStub     = $filesystem->get($migrationStubPath);

            $timestamp         = date('Y_m_d_His');
            $migrationFilePath = database_path("migrations/{$timestamp}_create_{$tableName}_table.php");

            $migrationContents = $this->populate($migrationStub, [
                'table'    => $tableName,
                'columns'  => implode("\n", $migrationColumns),
                'foreigns' => implode("\n", $migrationForeignKeys),
            ]);

            $this->writeFile($filesystem, $migrationFilePath, $migrationContents, $force, 'Migration');
        }

        // ---------- MODEL ----------
        if (!$this->option('no-model')) {
            [
                $fillableList,
                $castsList,
                $relationsList,
                $phpDocProperties,
                $allowedSorts,
                $allowedFilters,
                $allowedRelations
            ] = $this->modelBuilder->build($fields);


            $modelStubPath = $this->stubResolver->resolve((string) $this->option('stub-model'), 'model');
            $modelStub     = $filesystem->get($modelStubPath);

            $modelFilePath = app_path("Models/{$className}.php");

            $modelContents = $this->populate($modelStub, [
                'Class'            => $className,
                'model_namespace'  => (string) ($this->option('model-namespace') ?? 'App\\Models'),
                'table'            => $tableName,
                'primaryKey'       => 'id',
                'keyType'          => 'int',
                'allowed_sorts'     => implode(', ', array_map(fn($v) => "'{$v}'", $allowedSorts)),
                'allowed_filters'   => implode(', ', array_map(fn($v) => "'{$v}'", $allowedFilters)),
                'allowed_relations' => implode(', ', array_map(fn($v) => "'{$v}'", $allowedRelations)),
                'fillable'         => $this->lines($fillableList, 8),
                'casts'            => $this->lines($castsList, 8),
                'relations'        => $this->lines($relationsList, 0),
                'phpdoc_props'     => $this->lines($phpDocProperties, 1),
                'hidden'           => '',
                'appends'          => '',
            ]);


            $this->writeFile($filesystem, $modelFilePath, $modelContents, $force, 'Model');
        }


        // ---------- DTOs + Mapper ----------
        if (!$this->option('no-dtos') || !$this->option('no-mapper')) {
            $dtoNsRoot = 'App\\DTOs';
            $dtoNamespace = $dtoNsRoot . "\\{$className}";
            $dtoDir = base_path("app/DTOs/{$className}");

            $mapperNamespace = 'App\\Mappers';
            $mapperDir = base_path("app/Mappers");

            $modelNs = (string) ($this->option('model-namespace') ?? 'App\\Models');
            $modelFqcn = "{$modelNs}\\{$className}";

            $built = $this->dtoBuilder->build($className, $fields);

            if (!$this->option('no-dtos')) {
                // CREATE DTO
                $stubPath = $this->stubResolver->resolve((string) $this->option('stub-dto_create'), 'dto_create');
                $stub = $filesystem->get($stubPath);

                $content = $this->populate($stub, [
                    'dto_namespace' => $dtoNamespace,
                    'Class' => $className,
                    'ctor_props' => $this->lines($built['create']['ctor_props'], 0),
                    'from_validated_named_args' => $this->lines($built['create']['from_validated_named_args'], 0),
                    'to_array' => $this->lines($built['create']['to_array'], 0),
                ]);

                $this->writeFile($filesystem, $dtoDir . "/{$className}CreateDTO.php", $content, $force, 'DTO (Create)');

                // UPDATE DTO
                $stubPath = $this->stubResolver->resolve((string) $this->option('stub-dto_update'), 'dto_update');
                $stub = $filesystem->get($stubPath);

                $content = $this->populate($stub, [
                    'dto_namespace' => $dtoNamespace,
                    'Class' => $className,
                    'ctor_props' => $this->lines($built['update']['ctor_props'], 0),
                    'from_validated_named_args' => $this->lines($built['update']['from_validated_named_args'], 0),
                    'to_array' => $this->lines($built['update']['to_array'], 0),
                ]);

                $this->writeFile($filesystem, $dtoDir . "/{$className}UpdateDTO.php", $content, $force, 'DTO (Update)');

                // LIST ITEM DTO
                $stubPath = $this->stubResolver->resolve((string) $this->option('stub-dto_list_item'), 'dto_list_item');
                $stub = $filesystem->get($stubPath);

                $content = $this->populate($stub, [
                    'dto_namespace' => $dtoNamespace,
                    'Class' => $className,
                    'ctor_props' => $this->lines($built['list_item']['ctor_props'], 0),
                    'to_array' => $this->lines($built['list_item']['to_array'], 0),
                ]);

                $this->writeFile($filesystem, $dtoDir . "/{$className}ListItemDTO.php", $content, $force, 'DTO (ListItem)');

                // DETAIL DTO
                $stubPath = $this->stubResolver->resolve((string) $this->option('stub-dto_detail'), 'dto_detail');
                $stub = $filesystem->get($stubPath);

                $content = $this->populate($stub, [
                    'dto_namespace' => $dtoNamespace,
                    'Class' => $className,
                    'ctor_props' => $this->lines($built['detail']['ctor_props'], 0),
                    'to_array' => $this->lines($built['detail']['to_array'], 0),
                ]);

                $this->writeFile($filesystem, $dtoDir . "/{$className}DetailDTO.php", $content, $force, 'DTO (Detail)');
            }

            if (!$this->option('no-mapper')) {
                $detailDtoFqcn = "{$dtoNamespace}\\{$className}DetailDTO";
                $listDtoFqcn = "{$dtoNamespace}\\{$className}ListItemDTO";

                $stubPath = $this->stubResolver->resolve((string) $this->option('stub-mapper'), 'mapper');
                $stub = $filesystem->get($stubPath);

                $content = $this->populate($stub, [
                    'mapper_namespace' => $mapperNamespace,
                    'Class' => $className,
                    'detail_dto_fqcn' => $detailDtoFqcn,
                    'list_dto_fqcn' => $listDtoFqcn,
                    'mapper_contract_fqcn' => 'App\\Mappers\\Contracts\\ICrudMapper',
                    'model_fqcn' => $modelFqcn,

                    'model_short' => $className,
                    'detail_dto_short' => "{$className}DetailDTO",
                    'list_dto_short' => "{$className}ListItemDTO",

                    'list_dto_named_args' => $this->lines($built['list_item']['named_args'], 0),
                    'detail_dto_named_args' => $this->lines($built['detail']['named_args'], 0),
                ]);

                $this->writeFile($filesystem, $mapperDir . "/{$className}Mapper.php", $content, $force, 'Mapper');
            }
        }



        // ---------- REPOSITORY + INTERFACE ----------
        if (!$this->option('no-repo')) {
            $repoNsRoot   = (string) ($this->option('repo-namespace') ?? 'App\\Repositories');
            $contractsNs  = (string) ($this->option('contracts-namespace') ?? 'App\\Repositories\\Contracts');
            $repoDir      = base_path((string) ($this->option('repo-dir') ?? 'app/Repositories'));
            $contractsDir = base_path((string) ($this->option('contracts-dir') ?? 'app/Repositories/Contracts'));
            $modelNs      = (string) ($this->option('model-namespace') ?? 'App\\Models');

            $baseRepoFq   = (string) ($this->option('base-repo') ?? 'App\\Repositories\\Shared\\BaseRepository');
            $baseIRepoFq  = (string) ($this->option('base-irepo') ?? 'App\\Repositories\\Contracts\\IBaseRepository');

            $baseRepo     = Str::afterLast($baseRepoFq, '\\');
            $baseIRepo    = Str::afterLast($baseIRepoFq, '\\');

            // interface
            $irepoStubPath = $this->stubResolver->resolve((string) $this->option('stub-irepository'), 'irepository');
            $irepoStub     = $filesystem->get($irepoStubPath);

            $irepoContent  = $this->populate($irepoStub, [
                'contracts_namespace' => $contractsNs,
                'base_irepo_full'     => $baseIRepoFq,
                'base_interface'      => $baseIRepo,
                'Class'               => $className,
            ]);

            $irepoFile = $contractsDir . "/I{$className}Repository.php";
            $this->writeFile($filesystem, $irepoFile, $irepoContent, $force, 'Interface');

            // repository
            $repoStubPath = $this->stubResolver->resolve((string) $this->option('stub-repository'), 'repository');
            $repoStub     = $filesystem->get($repoStubPath);

            $repoContent  = $this->populate($repoStub, [
                'repo_namespace'      => $repoNsRoot . "\\{$className}",
                'contracts_namespace' => $contractsNs,
                'model_full'          => "{$modelNs}\\{$className}",
                'model_class'         => $className,
                'base_repo_full'      => $baseRepoFq,
                'base_repo'           => $baseRepo,
                'Class'               => $className,
            ]);

            $repoFile = $repoDir . "/{$className}/{$className}Repository.php";
            $this->writeFile($filesystem, $repoFile, $repoContent, $force, 'Repository');
        }

        // ---------- SERVICE + INTERFACE ----------
        if (!$this->option('no-service')) {
            $serviceNsRoot  = (string) ($this->option('service-namespace') ?? 'App\\Services');
            $serviceDir     = base_path((string) ($this->option('service-dir') ?? 'app/Services'));
            $baseServiceFq  = (string) ($this->option('base-service') ?? 'App\\Services\\Shared\\BaseService');
            $baseIServiceFq = (string) ($this->option('base-iservice') ?? 'App\\Services\\Shared\\IBaseService');

            $dtoNsRoot = 'App\\DTOs';
            $createDtoFq = $dtoNsRoot . "\\{$className}\\{$className}CreateDTO";
            $updateDtoFq = $dtoNsRoot . "\\{$className}\\{$className}UpdateDTO";

            $mapperFq = "App\\Mappers\\{$className}Mapper";
            $mapperShort = "{$className}Mapper";


            $serviceNs      = $serviceNsRoot . "\\{$className}";
            $servicePathDir = $serviceDir . "/{$className}";
            $irepoFq        = (string) (($this->option('contracts-namespace') ?? 'App\\Repositories\\Contracts') . "\\I{$className}Repository");
            $irepoShort     = "I{$className}Repository";

            // interface
            $iserviceStubPath = $this->stubResolver->resolve((string) $this->option('stub-iservice'), 'iservice');
            $iserviceStub     = $filesystem->get($iserviceStubPath);

            $iserviceContent  = $this->populate($iserviceStub, [
                'service_namespace'  => $serviceNs,
                'base_iservice_fqcn' => $baseIServiceFq,
                'Class'              => $className,
            ]);

            $iserviceFile = $servicePathDir . "/I{$className}Service.php";
            $this->writeFile($filesystem, $iserviceFile, $iserviceContent, $force, 'Service Interface');

            // service
            $serviceStubPath = $this->stubResolver->resolve((string) $this->option('stub-service'), 'service');
            $serviceStub     = $filesystem->get($serviceStubPath);

            $serviceContent  = $this->populate($serviceStub, [
                'service_namespace'   => $serviceNs,
                'repo_contract_fqcn'  => $irepoFq,
                'repo_contract_short' => $irepoShort,
                'base_service_fqcn'   => $baseServiceFq,

                'create_dto_fqcn'     => $createDtoFq,
                'update_dto_fqcn'     => $updateDtoFq,
                'create_dto_short'    => "{$className}CreateDTO",
                'update_dto_short'    => "{$className}UpdateDTO",

                'mapper_fqcn'         => $mapperFq,
                'mapper_short'        => $mapperShort,

                'Class'               => $className,
            ]);

            $serviceFile = $servicePathDir . "/{$className}Service.php";
            $this->writeFile($filesystem, $serviceFile, $serviceContent, $force, 'Service');
        }

        // ---------- REQUESTS ----------
        if (!$this->option('no-requests')) {
            $reqNsRoot   = (string) ($this->option('requests-namespace') ?? 'App\\Http\\Requests');
            $reqDir      = base_path((string) ($this->option('requests-dir') ?? 'app/Http/Requests'));
            $baseIndexFq = (string) ($this->option('base-index-request') ?? 'App\\Http\\Requests\\Shared\\BaseIndexRequest');
            $baseFormFq  = (string) ($this->option('base-form-request') ?? 'Illuminate\\Foundation\\Http\\FormRequest');

            $reqNs   = $reqNsRoot . "\\{$className}";
            $reqPath = $reqDir . "/{$className}";

            [$storeRules, $updateRules, $filterRules, $stringFilterFields, $storeSan, $updateSan, $storeMsgs] =
                $this->requestBuilder->build($fields);

            // list
            $listStubPath = $this->stubResolver->resolve((string) $this->option('stub-request_list'), 'request_list');
            $listStub     = $filesystem->get($listStubPath);
            $listContent  = $this->populate($listStub, [
                'request_namespace'       => $reqNs,
                'base_index_request_fqcn' => $baseIndexFq,
                'Class'                   => $className,
                'filter_rules'            => $this->lines($filterRules, 12),
                'string_filter_fields'    => $this->lines($stringFilterFields, 12),
            ]);
            $this->writeFile($filesystem, $reqPath . "/{$className}ListRequest.php", $listContent, $force, 'Request (List)');

            // store
            $storeStubPath = $this->stubResolver->resolve((string) $this->option('stub-request_store'), 'request_store');
            $storeStub     = $filesystem->get($storeStubPath);
            $storeContent  = $this->populate($storeStub, [
                'request_namespace'      => $reqNs,
                'base_form_request_fqcn' => $baseFormFq,
                'Class'                  => $className,
                'store_rules'            => $this->lines($storeRules, 12),
                'store_sanitizers'       => $this->lines($storeSan, 8),
                'store_messages'         => $this->lines($storeMsgs, 12),
            ]);
            $this->writeFile($filesystem, $reqPath . "/{$className}StoreRequest.php", $storeContent, $force, 'Request (Store)');

            // update
            $updateStubPath = $this->stubResolver->resolve((string) $this->option('stub-request_update'), 'request_update');
            $updateStub     = $filesystem->get($updateStubPath);
            $updateContent  = $this->populate($updateStub, [
                'request_namespace'      => $reqNs,
                'base_form_request_fqcn' => $baseFormFq,
                'Class'                  => $className,
                'update_rules'           => $this->lines($updateRules, 12),
                'update_sanitizers'      => $this->lines($updateSan, 8),
            ]);
            $this->writeFile($filesystem, $reqPath . "/{$className}UpdateRequest.php", $updateContent, $force, 'Request (Update)');
        }

        // ---------- CONTROLLER ----------
        if (!$this->option('no-controller')) {
            $controllerNs  = (string) ($this->option('controller-namespace') ?? 'App\\Http\\Controllers\\System');
            $controllerDir = base_path((string) ($this->option('controller-dir') ?? 'app/Http/Controllers/System'));
            $baseCtrlFq    = (string) ($this->option('base-controller') ?? 'App\\Http\\Controllers\\Shared\\BaseController');

            $dtoNsRoot = 'App\\DTOs';
            $createDtoFq = $dtoNsRoot . "\\{$className}\\{$className}CreateDTO";
            $updateDtoFq = $dtoNsRoot . "\\{$className}\\{$className}UpdateDTO";


            $reqNsRoot     = (string) ($this->option('requests-namespace') ?? 'App\\Http\\Requests');
            $reqNs         = $reqNsRoot . "\\{$className}";
            $indexReqFq    = $reqNs . "\\{$className}ListRequest";
            $storeReqFq    = $reqNs . "\\{$className}StoreRequest";
            $updateReqFq   = $reqNs . "\\{$className}UpdateRequest";

            $serviceNsRoot = (string) ($this->option('service-namespace') ?? 'App\\Services');
            $serviceFq     = $serviceNsRoot . "\\{$className}\\I{$className}Service";
            $serviceShort  = "I{$className}Service";

            $routePrefix   = (string) ($this->option('route-prefix') ?? Str::of($className)->snake()->toString());
            $tag           = (string) ($this->option('tag') ?? $className);
            $tagLower      = Str::of($tag)->snake(' ')->toString();
            $tagLowerPlural = Str::of($tag)->snake(' ')->plural()->toString();

            [$withArr, $filtersParams, $storeProps, $updateProps, $storeRequired] =
                $this->controllerBuilder->build($fields);

            $withList    = empty($withArr) ? '' : "'" . implode("','", $withArr) . "'";
            $withExample = empty($withArr) ? '—' : implode(',', $withArr);

            $ctrlStubPath = $this->stubResolver->resolve((string) $this->option('stub-controller'), 'controller');
            $ctrlStub     = $filesystem->get($ctrlStubPath);

            $content = $this->populate($ctrlStub, [
                'controller_namespace'    => $controllerNs,
                'base_controller_fqcn'    => $baseCtrlFq,
                'index_request_fqcn'      => $indexReqFq,
                'store_request_fqcn'      => $storeReqFq,
                'update_request_fqcn'     => $updateReqFq,
                'index_request_class'     => "\\{$indexReqFq}::class",
                'store_request_class'     => "\\{$storeReqFq}::class",
                'update_request_class'    => "\\{$updateReqFq}::class",
                'service_interface_fqcn'  => $serviceFq,
                'service_interface_short' => $serviceShort,
                'with_relations'          => $withList,
                'with_relations_example'  => $withExample,
                'route_prefix'            => $routePrefix,
                'tag'                     => $tag,
                'tag_lower'               => $tagLower,
                'tag_lower_plural'        => $tagLowerPlural,
                'store_required'          => implode(',', array_map(fn($f) => '"' . $f . '"', $storeRequired)),
                'filters_parameters'      => $this->commaLines($filtersParams, ' *     '),
                'store_body_properties'   => $this->commaLines($storeProps,  ' *       '),
                'update_body_properties'  => $this->commaLines($updateProps, ' *       '),
                'Class'                   => $className,
                'create_dto_fqcn'   => $createDtoFq,
                'update_dto_fqcn'   => $updateDtoFq,
                'create_dto_short'  => "{$className}CreateDTO",
                'update_dto_short'  => "{$className}UpdateDTO",

            ]);

            $file = $controllerDir . "/{$className}Controller.php";
            $this->writeFile($filesystem, $file, $content, $force, 'Controller');
        }

        // ---------- ROTAS ----------
        if (!$this->option('no-routes')) {
            $routesPath   = base_path((string) ($this->option('routes-file') ?? 'routes/api.php'));
            $marker       = (string) ($this->option('routes-marker') ?? '[router_marckenew_generates]');
            $middleware   = (string) ($this->option('routes-middleware') ?? 'auth.jwt');
            $controllerNs = (string) ($this->option('controller-namespace') ?? 'App\\Http\\Controllers\\System');

            $prefixOpt = $this->option('route-prefix');
            $prefix    = $prefixOpt
                ? Str::of((string) $prefixOpt)->snake()->toString()
                : Str::of($className)->snake()->toString();

            $controllerFQCN  = $controllerNs . "\\{$className}Controller";
            $controllerShort = "{$className}Controller";

            $block = <<<PHP
Route::prefix('{$prefix}')->controller({$controllerShort}::class)->group(function () {
    Route::get('/', 'index');
    Route::get('{id}', 'show')->whereNumber('id');
    Route::post('/', 'store');
    Route::put('{id}', 'update')->whereNumber('id');
    Route::delete('{id}', 'destroy')->whereNumber('id');
    Route::post('{id}/restore', 'restore')->whereNumber('id');
    Route::delete('{id}/force', 'forceDelete')->whereNumber('id');
});
PHP;

            $this->routeInjector->inject(
                $routesPath,
                $controllerFQCN,
                $controllerShort,
                $block,
                $marker,
                $middleware,
                $prefix
            );

            $this->info("Routes injetadas em: {$routesPath}");
        }

        // ---------- PROVIDERS ----------
        if (!$this->option('no-providers')) {
            $contractsNs        = (string) ($this->option('contracts-namespace') ?? 'App\\Repositories\\Contracts');
            $repoNsRoot         = (string) ($this->option('repo-namespace') ?? 'App\\Repositories');
            $serviceNsRoot      = (string) ($this->option('service-namespace') ?? 'App\\Services');

            $repoProviderPath   = base_path((string) ($this->option('repo-provider-file') ?? 'app/Providers/RepositoryServiceProvider.php'));
            $serviceProviderPath = base_path((string) ($this->option('service-provider-file') ?? 'app/Providers/ServicesServiceProvider.php'));
            $marker             = (string) ($this->option('provider-marker') ?? '[coresuit_providers]');

            $iRepoFq  = "{$contractsNs}\\I{$className}Repository";
            $repoFq   = "{$repoNsRoot}\\{$className}\\{$className}Repository";
            $iSrvFq   = "{$serviceNsRoot}\\{$className}\\I{$className}Service";
            $srvFq    = "{$serviceNsRoot}\\{$className}\\{$className}Service";

            $bindRepo = "\$this->app->bind(" . Str::afterLast('I' . $className . 'Repository', '\\') . "::class, " . Str::afterLast($className . 'Repository', '\\') . "::class);";
            $bindSrv  = "\$this->app->bind(" . Str::afterLast('I' . $className . 'Service', '\\') . "::class, " . Str::afterLast($className . 'Service', '\\') . "::class);";

            $this->providerInjector->inject(
                $repoProviderPath,
                ["Illuminate\\Support\\ServiceProvider", $iRepoFq, $repoFq],
                $bindRepo,
                $marker,
                'RepositoryServiceProvider'
            );

            $this->providerInjector->inject(
                $serviceProviderPath,
                ["Illuminate\\Support\\ServiceProvider", $iSrvFq, $srvFq],
                $bindSrv,
                $marker,
                'ServicesServiceProvider'
            );

            $this->info("Providers atualizados.");
        }

        $this->line("→ Rode: php artisan migrate (se gerou migration)");
        return 0;
    }

    // ----------------- HELPERS -----------------

    /**
     * Só permite rodar se o init tiver sido executado.
     * Checa o flag bootstrap/cache/coresuit_init.flag ou,
     * na ausência, verifica a existência de arquivos-base.
     */
    private function ensureInitOrFail(Filesystem $fs): bool
    {
        $flagPath = base_path('bootstrap/cache/coresuit_init.flag');
        if ($fs->exists($flagPath)) {
            return true;
        }

        $required = [
            'app/Repositories/Shared/BaseRepository.php',
            'app/Repositories/Contracts/IBaseRepository.php',
            'app/Services/Shared/BaseService.php',
            'app/Services/Shared/IBaseService.php',
            'app/DTOs/Shared/IndexQueryDTO.php',
            'app/Http/Requests/Shared/BaseIndexRequest.php',
            'app/Http/Requests/Shared/IndexRequest.php',
            'app/Http/Controllers/Shared/BaseController.php',
            'app/Providers/RepositoryServiceProvider.php',
            'app/Providers/ServicesServiceProvider.php',
            // opcional, mas considerado no init
            'app/OpenApi/OpenApi.php',
        ];

        $missing = [];
        foreach ($required as $rel) {
            if (!$fs->exists(base_path($rel))) {
                $missing[] = $rel;
            }
        }

        if (empty($missing)) {
            // Se tudo existe, deixa passar mesmo sem flag
            return true;
        }

        $this->warn('Setup inicial não encontrado. Para começar a gerar CRUDs, rode primeiro:');
        $this->line('  php artisan coresuit:make-migration init');
        $this->line('');
        $this->line('Arquivos ausentes (exemplos):');
        foreach (array_slice($missing, 0, 5) as $m) {
            $this->line('  - ' . $m);
        }
        if (count($missing) > 5) {
            $this->line('  ...');
        }

        return false;
    }

    private function writeFile(Filesystem $fs, string $path, string $content, bool $force, string $label): void
    {
        if (!$fs->isDirectory(dirname($path))) {
            $fs->makeDirectory(dirname($path), 0755, true);
        }
        if ($fs->exists($path) && !$force) {
            $this->warn("$label já existe: {$path} (use --force para sobrescrever).");
            return;
        }
        $fs->put($path, $content);
        $this->info("$label criado: {$path}");
    }

    private function populate(string $tpl, array $ctx): string
    {
        foreach ($ctx as $k => $v) {
            $tpl = str_replace('{{' . $k . '}}', (string) $v, $tpl);
        }
        return $tpl;
    }

    private function parseFields(string $raw): array
    {
        if (trim($raw) === '') return [];
        $out = [];
        foreach (preg_split('/\s*,\s*/', $raw) as $chunk) {
            if ($chunk === '') continue;
            [$name, $type, $required] = array_pad(explode(':', $chunk), 3, null);
            $out[] = [
                'name'     => Str::of((string) $name)->snake()->toString(),
                'type'     => $type ? strtolower((string) $type) : 'string',
                'required' => filter_var($required, FILTER_VALIDATE_BOOL),
            ];
        }
        return $out;
    }

    private function lines(array $arr, int $indentSpaces = 4): string
    {
        $indent = str_repeat(' ', $indentSpaces);
        return implode("\n", array_map(fn($l) => $indent . $l, $arr));
    }

    private function commaLines(array $items, string $prefix): string
    {
        $out = [];
        $total = count($items);
        foreach ($items as $i => $line) {
            $comma = ($i < $total - 1) ? ',' : '';
            $out[] = $prefix . $line . $comma;
        }
        return implode("\n", $out);
    }
}
