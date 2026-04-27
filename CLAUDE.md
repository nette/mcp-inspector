# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

`nette/mcp-inspector` is an MCP (Model Context Protocol) server for Nette application introspection. It allows AI assistants to inspect DI containers, database schemas, routing, and other Nette components.

Bude využíván zejména v Claude Code a dostupný přes **Nette Plugins for Claude Code**. Jeho repozitář je v `/mnt/w/Nette/x-trifles/Claude-Code`

## Architecture

### Core Components

- **`bin/mcp-inspector`** — entry script with `nette/command-line` CLI flags (`--project`, `--bootstrap`)
- **`Server`** — MCP server orchestrator with dual transport support (CLI/HTTP)
- **`ServerFactory`** — instance class that wires `BootstrapBridge`, built-in toolkits, and project toolkits into a `Server`. Subclass it to plug in custom discovery
- **`Toolkit`** — marker interface for toolkit classes (auto-discovery via `Container::findByType`)
- **`ContainerAccessor`** — interface that toolkits depend on instead of `Container` directly. Lets the bridge swap the underlying container source (live rebuild, mock, HTTP-served, …) without changing toolkit code
- **`BootstrapBridge`** — concrete `ContainerAccessor` implementation. Loads `mcp-bootstrap.php` (Closure → Container) and rebuilds the container on every call so config edits are picked up live; on rebuild failure keeps the last-known-good container as fallback
- **Project's `mcp-bootstrap.php`** — required convention file in project root that returns `Closure(): Nette\DI\Container`

### Project Layout Convention

Each project must contain `mcp-bootstrap.php` at its root. The file returns a `Closure` that produces a fully built `Nette\DI\Container`. Two typical shapes:

**Skeleton with static `App\Bootstrap::boot()`:**

```php
<?php declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
return fn() => App\Bootstrap::boot()->createContainer();
```

**Custom Bootstrap with instance constructor:**

```php
<?php declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
return fn() => (new App\Bootstrap(App\Blog::LaTrine))->bootConsoleApplication();
```

**Important:** `new Configurator` should happen *inside* the App\Bootstrap class, not in `mcp-bootstrap.php` directly. Nette autodetects `%appDir%` from `dirname($trace[1]['file'])` (the file that instantiates Configurator). If you build it in `mcp-bootstrap.php`, `%appDir%` resolves to the project root instead of `app/`, which makes RobotLoader scan `node_modules` and other unintended directories.

Likewise, `%wwwDir%` autodetection from the entry frame yields `bin/mcp-inspector`'s directory; projects that need a real `%wwwDir%` should set it explicitly via `$configurator->addStaticParameters(['wwwDir' => ...])` in their bootstrap method.

### ContainerAccessor + BootstrapBridge

Toolkits depend on the **`ContainerAccessor`** interface (in the root namespace, alongside `Toolkit`), not on a concrete bridge or `Container`. Two methods:

```php
interface ContainerAccessor
{
    public function getContainer(): Container;
    public function decorateResult(array $result): array;
}
```

**`BootstrapBridge`** (in `Bridge\` namespace) is the standard implementation. Behaviour:

- **Rebuilds on every `getContainer()` call.** The bootstrap closure is cached, but Nette's compiled container is read from disk cache — when configs are unchanged the rebuild is a `require` of an already-generated class. Config edits between tool calls are therefore picked up **without restarting the MCP server**.
- **Long-running-process live reload.** PHP cannot redeclare a class once required, but `nette/di` ≥ 3.2 (with the live-reload patch) detects this case in auto-rebuild mode and `eval()`s a uniquely-named copy of the regenerated container into memory. The MCP server therefore picks up config edits instantly — no class redeclare error, no stale data. Production code paths are untouched (the eval branch is gated behind `autoRebuild=true`, never reached when the application runs in production mode).
- **Last-known-good fallback.** If a rebuild fails (typo in `common.neon`, broken service factory, etc.), the bridge keeps the previously valid container and returns it. The error is exposed via `getLastError()` and surfaced to the user via `decorateResult()` (adds a `_warning` key to the result).
- **First-call failure is fatal.** With no fallback container available, the underlying exception is rethrown so the MCP server reports a hard error rather than serving nothing.

Toolkits **must** pipe their results through `$this->accessor->decorateResult($result)` so transient compile failures are visible to the user.

There is intentionally **no** `getContainerBuilder()`. All toolkits read from the runtime `Container` only — compile-time-only data (factory expressions, setup() calls, beforeCompile hooks, registered DI extensions) is not exposed by the inspector.

Container introspection relies on the public API in `nette/di`:

- `Container::getServiceTypes(): array<string, string>` — name → type
- `Container::getAliases(): array<string, string>` — alias → canonical
- `Container::getInstantiatedServices(): array<string, object>` — name → instance
- `Container::getServiceTags(string $name): array<string, mixed>` — tags of a service
- `Container::findAutowired(string $type): list<string>` — promoted from `@internal`
- plus the existing `findByType`, `findByTag`, `getServiceType`, `getParameters`, `hasService`

If a future toolkit needs ServiceDefinition-level data (factory expression, setup calls), expose it via a deliberate, documented method on Container/Compiler — do not reach into the builder behind users' backs.

### ServerFactory

Instance class. Subclass and override the `protected` hooks to customise:

```php
class ServerFactory
{
    protected const BuiltInToolkits = [
        DIToolkit::class, DatabaseToolkit::class, RouterToolkit::class, TracyToolkit::class,
    ];

    public function __construct(
        protected string $projectDir,
        protected string $bootstrapPath = 'mcp-bootstrap.php',
    ) {}

    public function create(): Server
    {
        $accessor = new BootstrapBridge($this->projectDir, $this->bootstrapPath);
        $server = new Server;
        // Project toolkits go FIRST so they can override a built-in of the same kind.
        $this->registerProjectToolkits($server, $accessor->getContainer());
        $this->registerBuiltInToolkits($server, $accessor);
        return $server;
    }

    // Built-in toolkits get the ContainerAccessor — they re-resolve the container on every call.
    protected function registerBuiltInToolkits(Server $server, ContainerAccessor $accessor): void { /* ... */ }
    // Project toolkits are constructed once via DI; they receive the initial Container only.
    protected function registerProjectToolkits(Server $server, Container $container): void { /* ... */ }
}
```

**Override semantics.** A project toolkit registered through `services.neon` overrides a
built-in of the same kind when it is `instanceof` that built-in. Mechanics:

1. `registerProjectToolkits` adds project toolkits first.
2. `registerBuiltInToolkits` walks `BuiltInToolkits` and skips any class for which
   `Server::hasToolkitOfType($builtinClass)` already returns true (i.e. some
   project toolkit `instanceof` it).

Examples:
- `class MyDIToolkit extends DIToolkit` → replaces the default `DIToolkit` entirely.
- `class StatsToolkit implements Toolkit` (no `extends`) → coexists with all built-ins.
- `services: - DIToolkit` (project naively re-registers a built-in by exact class) →
  the built-in `tryCreate` is skipped, no double registration.

### Server

PSR-17 factories live in the **constructor**, not in `runHttp()`:

```php
$server = new Server($responseFactory, $streamFactory);
$server->runHttp($request);   // returns ResponseInterface

// CLI doesn't need any factories:
$server = new Server;
$server->runCli();
```

`Server::build()` reflects each toolkit instance, finds methods carrying `#[McpTool]`, and registers each one with the SDK builder using a first-class callable closure:

```php
$builder->addTool(
    handler: $toolkit->$methodName(...),
    name: $attr->name ?? $methodName,
);
```

Why a Closure: `Mcp\Capability\Discovery\HandlerResolver::resolve()` accepts three handler shapes — `Closure`, `[ClassName::class, 'method']` (two strings), or `InvokableClass::class`. The array form requires the SDK to instantiate the class itself (via PSR-11 container or no-arg `new`), which doesn't fit our toolkits that are constructed in `ServerFactory::create()` with their own typed dependencies.

PHP 8.1 first-class callable syntax `$toolkit->method(...)` produces a `Closure` already bound to the existing instance. `ReferenceHandler::handle()` invokes it via `call_user_func($closure, ...$args)` — no container, no class resolution, no `addToolsFromObject()` (which doesn't exist in the SDK public API).

`Server::hasToolkitOfType(string $class): bool` lets `ServerFactory` implement override semantics — see [ServerFactory](#serverfactory).

### Masking

`Nette\McpInspector\Masking::shouldMask(string $key): bool` — single source of truth for deciding whether a configuration key likely holds a secret. Used by `DIToolkit` (parameter masking) and `TracyLogger` (sanitized log payloads). Bare `key` substring is intentionally **not** in the keyword list — too many false positives (`cacheKey`, `lookupKey`, `keyValueStore`); real API keys are matched via `apikey` / `api_key`.

### Toolkits

Two ways to add a toolkit:

**Built-in** — register class name in `ServerFactory::BuiltInToolkits`. Must expose static `tryCreate(ContainerAccessor $accessor): ?self` factory that returns `null` if its dependencies are missing. Built-ins **store the accessor**, not resolved services, so they pick up config-reload changes; they call `$this->accessor->getContainer()->getByType(...)` per invocation and pipe results through `$this->accessor->decorateResult()`:

```php
class MyBuiltInToolkit implements Toolkit
{
    public static function tryCreate(ContainerAccessor $accessor): ?self
    {
        // Probe for required services; bail out if the project doesn't provide them.
        try {
            $accessor->getContainer()->getByType(SomeService::class);
        } catch (\Throwable) {
            return null;
        }
        return new self($accessor);
    }

    public function __construct(private ContainerAccessor $accessor) {}

    /**
     * Tool description from PHPDoc (first line/paragraph).
     * @param string $param Parameter description for AI
     */
    #[McpTool(
        name: 'my_tool',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    public function myMethod(string $param): array
    {
        $service = $this->accessor->getContainer()->getByType(SomeService::class);
        return $this->accessor->decorateResult(['result' => $service->process($param)]);
    }
}
```

**Why not inject `SomeService` directly into the constructor?** The accessor may rebuild the container between tool calls (config edit, hot-reload). A captured service reference would point at a stale container's instance. Re-resolving per call costs essentially nothing — Nette's compiled container caches the lookup.

**Project-specific** — register as a regular service in the project's `services.neon`. `ServerFactory::registerProjectToolkits()` finds it via `Container::findByType(Toolkit::class)`. No `tryCreate` needed; standard constructor injection works. Project toolkits are constructed once when the server starts (DI lifecycle), so they don't get config-reload semantics — if you need them, depend on `ContainerAccessor` instead of concrete services and follow the built-in pattern above:

```neon
# services.neon
services:
    - App\Mcp\BlogToolkit
```

```php
namespace App\Mcp;

class BlogToolkit implements Toolkit
{
    public function __construct(
        private BlogFacade $blogs,
    ) {}

    #[McpTool(name: 'blog_get_post')]
    public function getPost(int $id): array { /* ... */ }
}
```

### Built-in Toolkits

- **`DIToolkit`** — DI container introspection (services, parameters, tags, aliases, autowiring)
- **`DatabaseToolkit`** — Database schema inspection (tables, columns, relationships, indexes, EXPLAIN)
- **`RouterToolkit`** — Routing inspection (routes, URL matching, URL generation)
- **`TracyToolkit`** — Tracy debugger introspection (exceptions, warnings, logs)

### DIToolkit Tools

| Tool | Description |
|------|-------------|
| `di_get_services` | List services with name → type, filterable |
| `di_get_service` | Service detail (type, tags, instantiation status) |
| `di_get_parameter_names` | Flat list of all parameter names (nested values use dotted notation `database.default.dsn`) |
| `di_get_parameter` | Single parameter value by name; `Masking::shouldMask()` applied to sensitive leaf keys; Windows paths normalized to forward slashes |
| `di_get_aliases` | Alias → canonical name map |
| `di_find_by_tag` | Find services by tag |
| `di_find_by_type` | Find services by type/interface (with autowired flag) |

### DatabaseToolkit Tools

| Tool | Description |
|------|-------------|
| `db_get_tables` | List database tables |
| `db_get_columns` | Get table columns with FK info |
| `db_get_relationships` | Get all FK relationships |
| `db_get_indexes` | Get table indexes |
| `db_explain_query` | EXPLAIN for SELECT queries |
| `db_generate_entity` | Generate PHP entity code |

### RouterToolkit Tools

| Tool | Description |
|------|-------------|
| `router_get_routes` | List all routes |
| `router_match_url` | Match URL to presenter |
| `router_generate_url` | Generate URL for presenter |

### TracyToolkit Tools

| Tool | Description |
|------|-------------|
| `tracy_get_last_exception` | Get last exception details |
| `tracy_get_exceptions` | List recent exception files |
| `tracy_get_exception` | Get specific exception details (by HTML filename) |
| `tracy_get_warnings` | Get PHP warnings |
| `tracy_get_log` | Get entries from any log level |

### TracyLogger (optional)

`Nette\McpInspector\TracyLogger` is a standalone Tracy logger for structured JSON export. **Not used by MCP-Inspector itself** — opt-in for projects that want machine-readable logs:

```php
Tracy\Debugger::setLogger(new TracyLogger());
```

Features:
- Writes to `log/mcp_telemetry.jsonl`
- Structured JSON Lines format
- Sensitive data masking
- Automatic file rotation (10MB, keeps 5 rotated)

## MCP SDK Attributes

### McpTool

```php
#[McpTool(
    name: 'tool_name',           // optional, defaults to method name
    description: 'Description',  // optional, defaults to PHPDoc
    annotations: new ToolAnnotations(...),
)]
```

### ToolAnnotations

```php
new ToolAnnotations(
    title: 'Human Title',        // human-readable title
    readOnlyHint: true,          // tool doesn't modify environment
    destructiveHint: false,      // tool doesn't destroy data
    idempotentHint: true,        // repeatable without side effects
    openWorldHint: false,        // closed domain (not web search etc.)
)
```

### Schema (parameter validation)

```php
use Mcp\Capability\Attribute\Schema;

#[McpTool(name: 'example')]
public function example(
    #[Schema(minLength: 1, maxLength: 100)]
    string $name,

    #[Schema(minimum: 0, maximum: 100)]
    int $percentage,

    #[Schema(enum: ['asc', 'desc'])]
    string $order = 'asc',
): array
```

## Coding Standards

- Follow Nette coding standards
- Use PHP 8.3+ features (readonly, enums, named arguments)
- All tool methods must return `array`
- Use PHPDoc for tool and parameter descriptions (SDK extracts them automatically)
- Use `ToolAnnotations` to hint tool behavior (readOnlyHint, idempotentHint)

## Adding New Toolkits

**For built-in (ships with MCP Inspector):**

1. Create class in `src/McpInspector/Toolkits/` implementing `Toolkit`
2. Add static `tryCreate(ContainerAccessor $accessor): ?self` factory method that probes for required services (return `null` if missing)
3. Store the `ContainerAccessor` (not concrete services) so config reload is honoured
4. Add methods with `#[McpTool]` attribute and `ToolAnnotations`; resolve services per call via `$this->accessor->getContainer()->getByType(...)` and pipe results through `$this->accessor->decorateResult($result)`
5. Register class name in `ServerFactory::BuiltInToolkits`

**For project-specific (lives in user code):**

1. Create class implementing `Toolkit` somewhere in your project's `app/`
2. Inject dependencies via constructor — standard Nette DI
3. Register in `services.neon` like any other service
4. `ServerFactory::registerProjectToolkits()` finds it automatically

**For overriding discovery logic:**

Subclass `ServerFactory` and override `registerBuiltInToolkits()` or `registerProjectToolkits()`.

## Testing

Run tests:
```bash
composer tester
```

Run MCP server locally:
```bash
php bin/mcp-inspector --project=/path/to/project
```

Smoke-test via stdin (initialize + tools/list):

```bash
{
  echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1.0"}}}'
  echo '{"jsonrpc":"2.0","method":"notifications/initialized"}'
  echo '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
} | php bin/mcp-inspector --project=/path/to/project
```

Test in Claude Code by adding to `.mcp.json`:
```json
{
    "mcpServers": {
        "nette-inspector": {
            "command": "php",
            "args": [
                "vendor/nette/mcp-inspector/bin/mcp-inspector",
                "--project=/absolute/path/to/project"
            ]
        }
    }
}
```

`--project` is recommended over relying on host cwd — MCP clients spawn the server with unpredictable working directories.
