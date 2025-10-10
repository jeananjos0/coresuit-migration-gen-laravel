# CoreSuit Migration Gen (Laravel)

Gerador de **Migration + Model + Repository/Interface + Service/Interface + Requests + Controller** com **injeção automática de Rotas e Providers** para projetos Laravel — no estilo **CoreSuit**.

> Compatível com Laravel 11/12+ e PHP >= 8.2

---

## Sumário
- [Instalação no seu projeto (consumidor)](#instalação-no-seu-projeto-consumidor)
- [Primeiros passos (init)](#primeiros-passos-init)
- [Como gerar uma entidade](#como-gerar-uma-entidade)
- [Sintaxe de campos (`--fields`)](#sintaxe-de-campos---fields)
- [Arquivos e estrutura gerada](#arquivos-e-estrutura-gerada)
- [Customização com stubs e namespaces](#customização-com-stubs-e-namespaces)
- [Injeção de rotas e providers](#injeção-de-rotas-e-providers)
- [Comandos disponíveis](#comandos-disponíveis)
- [Como usar o pacote em outro projeto Laravel](#como-usar-o-pacote-em-outro-projeto-laravel)
- [Como publicar uma nova versão no Packagist (maintainer)](#como-publicar-uma-nova-versão-no-packagist-maintainer)
- [Boas práticas de versionamento](#boas-práticas-de-versionamento)
- [Solução de problemas (FAQ)](#solução-de-problemas-faq)
- [Licença](#licença)

---

## Instalação no seu projeto (consumidor)

> **Pré-requisitos**: Projeto Laravel com Composer configurado.

Instale a versão estável mais recente:
```bash
composer require coresuit/migration-gen-laravel
```

Ou uma faixa específica (ex.: série 1.3):
```bash
composer require coresuit/migration-gen-laravel:^1.3
```

Se (temporariamente) você precisa testar a branch de desenvolvimento:
```bash
composer require coresuit/migration-gen-laravel:dev-master
```
> **Não recomendado para produção.**

---

## Primeiros passos (`init`)

Antes de gerar entidades, execute o **init** para preparar bases de Repository/Service/DTO/Requests/Controller, registrar **Providers**, criar **OpenAPI bootstrap** e rota de **health**.

```bash
php artisan coresuit:make-migration init
```

O que o `init` faz:
- Cria bases em:
  - `app/Repositories`, `app/Services`, `app/DTOs/Shared`
  - `app/Http/Requests/Shared`, `app/Http/Controllers/Shared`
- Cria `app/OpenApi/OpenApi.php` (bootstrap OpenAPI).
- Registra providers (`RepositoryServiceProvider` e `ServicesServiceProvider`) usando marcador `[coresuit_providers]`.
- Gera rota `/api/health` e controller de health check.
- Cria flag `bootstrap/cache/coresuit_init.flag` que libera o gerador.

> **Swagger (opcional)**: para documentação da API com L5 Swagger
```bash
composer require darkaonline/l5-swagger
php artisan package:discover --ansi
php artisan l5-swagger:generate
# A doc padrão fica em /api/documentation
```

---

## Como gerar uma entidade

Exemplo criando **Pedido** com campos e relacionamentos:
```bash
php artisan coresuit:make-entity \
  --name=Pedido \
  --fields="cliente_id:int:true,data_pedido:date:true,observacao:text:false,valor_total:decimal:true,ativo:boolean:false"
```

Depois, aplique a migration:
```bash
php artisan migrate
```

### O que é criado
- **Migration**: `database/migrations/<timestamp>_create_pedidos_table.php`
- **Model**: `app/Models/Pedido.php`
- **Repository + Interface**
- **Service + Interface**
- **Requests** (List/Store/Update)
- **Controller** REST
- **Rotas** (prefixo `pedido` por padrão)
- **Providers** (binds automáticos)

---

## Sintaxe de campos (`--fields`)

Formato: `nome:type:required`, separados por vírgula.

- **nome**: será normalizado para `snake_case`.  
- **type**: `string`, `text`, `int`/`integer`, `bigint`, `decimal`, `bool`/`boolean`, `date`, `datetime`, `json`.  
- **required**: `true` | `false` (controla `nullable()` e validações).

Exemplo completo:
```
cliente_id:int:true,observacao:text:false,valor_total:decimal:true,ativo:boolean:false,data_pedido:date:true
```

**Regra especial**: campos que terminam com **`_id`**
- Geram `belongsTo` automaticamente no Model (ex.: `cliente()`).
- Criam foreign key na migration (`references('id')->on('clientes')`).

Impactos por tipo:
- **Migration**: `string`, `text`, `unsignedInteger`, `unsignedBigInteger`, `decimal(18,2)`, `boolean`, `date`, `dateTime`, `json`.
- **Model**: adiciona **casts** adequados (ex.: `decimal:2`, `boolean`, `array`, `date`, `datetime`).
- **Requests**: validações, sanitização (ex.: trim/boolean) e filtros no `ListRequest` (query params `filters[...]`).

---

## Arquivos e estrutura gerada

```
app/
  DTOs/Shared/IndexQueryDTO.php
  Http/
    Controllers/
      Shared/BaseController.php
      System/<Entity>Controller.php
    Requests/
      Shared/{BaseIndexRequest,IndexRequest}.php
      <Entity>/{<Entity>ListRequest,<Entity>StoreRequest,<Entity>UpdateRequest}.php
  Models/<Entity>.php
  OpenApi/OpenApi.php
  Providers/{RepositoryServiceProvider,ServicesServiceProvider}.php
  Repositories/
    Contracts/{IBaseRepository.php,I<Entity>Repository.php}
    <Entity>/<Entity>Repository.php
  Services/
    Shared/{BaseService.php,IBaseService.php}
    <Entity>/{I<Entity>Service.php,<Entity>Service.php}

routes/api.php     # contém // [router_marckenew_generates]
bootstrap/cache/coresuit_init.flag
```

---

## Customização com stubs e namespaces

Você pode **apontar stubs personalizados** e **alterar namespaces/diretórios**:

```bash
php artisan coresuit:make-entity \
  --name=Produto \
  --fields="nome:string:true,preco:decimal:true,categoria_id:int:false" \
  --stub-migration=stubs/coresuit/migration.stub \
  --stub-model=stubs/coresuit/model.stub \
  --stub-repository=stubs/coresuit/repository.stub \
  --stub-irepository=stubs/coresuit/irepository.stub \
  --stub-service=stubs/coresuit/service.stub \
  --stub-iservice=stubs/coresuit/iservice.stub \
  --stub-request_list=stubs/coresuit/request_list.stub \
  --stub-request_store=stubs/coresuit/request_store.stub \
  --stub-request_update=stubs/coresuit/request_update.stub \
  --stub-controller=stubs/coresuit/controller.stub \
  --model-namespace="App\\Models" \
  --repo-namespace="App\\Repositories" \
  --contracts-namespace="App\\Repositories\\Contracts" \
  --repo-dir="app/Repositories" \
  --contracts-dir="app/Repositories/Contracts" \
  --base-repo="App\\Repositories\\Shared\\BaseRepository" \
  --base-irepo="App\\Repositories\\Contracts\\IBaseRepository" \
  --service-namespace="App\\Services" \
  --service-dir="app/Services" \
  --base-service="App\\Services\\Shared\\BaseService" \
  --base-iservice="App\\Services\\Shared\\IBaseService" \
  --requests-namespace="App\\Http\\Requests" \
  --requests-dir="app/Http/Requests" \
  --base-index-request="App\\Http\\Requests\\Shared\\BaseIndexRequest" \
  --base-form-request="Illuminate\\Foundation\\Http\\FormRequest" \
  --controller-namespace="App\\Http\\Controllers\\System" \
  --controller-dir="app/Http/Controllers/System" \
  --route-prefix="produto" \
  --tag="Produto"
```

Flags úteis: `--no-migration`, `--no-model`, `--no-repo`, `--no-service`, `--no-requests`, `--no-controller`, `--no-routes`, `--no-providers`, `--force`.

---

## Injeção de rotas e providers

**Rotas** (em `routes/api.php`) com grupo de middleware (default `auth.jwt`) e marcador:
```php
Route::prefix('pedido')->controller(PedidoController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('{id}', 'show')->whereNumber('id');
    Route::post('/', 'store');
    Route::put('{id}', 'update')->whereNumber('id');
    Route::delete('{id}', 'destroy')->whereNumber('id');
    Route::post('{id}/restore', 'restore')->whereNumber('id');
    Route::delete('{id}/force', 'forceDelete')->whereNumber('id');
});
```

**Providers**: binds injetados em `RepositoryServiceProvider` e `ServicesServiceProvider` abaixo do marcador `// [coresuit_providers]`.

---

## Comandos disponíveis

- `php artisan coresuit:make-migration init` — prepara o projeto (bases, providers, OpenAPI, health, flag).
- `php artisan coresuit:make-entity --name=... --fields="..."` — gera stack CRUD.

Ajuda rápida:
```bash
php artisan list | grep coresuit
php artisan help coresuit:make-entity
```

---

## Como usar o pacote em outro projeto Laravel

1. **Instale o pacote**:
   ```bash
   composer require coresuit/migration-gen-laravel
   ```
2. **Execute o init** (uma vez por projeto):
   ```bash
   php artisan coresuit:make-migration init
   ```
3. **Gere entidades** conforme necessidade:
   ```bash
   php artisan coresuit:make-entity --name=Cliente --fields="nome:string:true,documento:string:false,ativo:boolean:false"
   php artisan migrate
   ```
4. **Customize** (opcional): publique stubs para editar templates
   ```bash
   php artisan vendor:publish --tag=coresuit-stubs
   # edite em stubs/coresuit/*.stub
   ```

---

## Como publicar uma nova versão no Packagist (maintainer)

> Para o **mantenedor** do pacote.

1. Garanta commits na `master`:
   ```bash
   git add .
   git commit -m "feat: nova funcionalidade"
   git push origin master
   ```
2. **Crie a tag semântica** e envie:
   ```bash
   git tag -a v1.3.0 -m "Release 1.3.0"
   git push origin v1.3.0
   ```
3. **Atualize no Packagist**:
   - Manual: botão **Update** na página do pacote; **ou**
   - Automático (recomendado): Configure um **webhook** no GitHub  
     - Settings → Webhooks → **Add webhook**  
     - **Payload URL**: `https://packagist.org/api/github`  
     - **Content type**: `application/json`  
     - **Events**: *Just the push event*

**Importante**: evite manter `"version"` no `composer.json`. Deixe o Packagist inferir a versão pela **tag**.  
Se optar por manter `"version"`, ela **deve** ser exatamente igual à tag (ex.: `1.3.0`).

**Corrigir tag equivocada**:
```bash
git tag -d v1.2.9
git push origin :refs/tags/v1.2.9
git tag -a v1.2.9 COMMIT_HASH -m "Release 1.2.9"
git push origin v1.2.9
```

---

## Boas práticas de versionamento

- **MAJOR** `2.0.0`: mudanças que quebram compatibilidade.
- **MINOR** `1.3.0 -> 1.4.0`: novas features compatíveis.
- **PATCH** `1.3.0 -> 1.3.1`: correções e micro melhorias.
- Use CHANGELOG (Releases do GitHub) para destacar mudanças.

---

## Solução de problemas (FAQ)

**“Could not find a version matching your minimum-stability (stable)”**  
- Publique uma tag estável (ex.: `v1.0.0`) OU instale `:dev-master` temporariamente.  
- Alternativa:
  ```json
  {
    "minimum-stability": "dev",
    "prefer-stable": true
  }
  ```

**“Some tags were ignored because of a version mismatch in composer.json”**  
- Remova `"version"` do `composer.json` ou sincronize com a tag.  
- Clique **Update** no Packagist (ou configure webhook).

**“Not auto-updated” no Packagist**  
- Ative o webhook do GitHub: `https://packagist.org/api/github` (evento `push`).

**“tag already exists” ao criar nova tag**  
```bash
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z
git tag -a vX.Y.Z -m "Release X.Y.Z"
git push origin vX.Y.Z
```

**`coresuit:make-entity` acusa que init não foi executado**  
- Rode: `php artisan coresuit:make-migration init`

---

## Licença
MIT — ver `LICENSE`.
