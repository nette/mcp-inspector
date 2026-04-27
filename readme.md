# Nette MCP Inspector

MCP (Model Context Protocol) server for Nette application introspection. Allows AI assistants to inspect your Nette application's DI container, database schema, routing, and more.

<img width="2816" height="1406" alt="image" src="https://github.com/user-attachments/assets/35a314bb-065b-486f-8e58-972bdb45516c" />

> ⚠️ **Development tool only.** Inspector exposes the project's full DI graph and configuration (sensitive keys are masked on a best-effort basis only). It refuses to start against an application running in production mode; override with `MCP_INSPECTOR_ALLOW_PRODUCTION=1` only if you fully understand the exposure.

## Installation

With [Claude Code](https://claude.com/product/claude-code) and the [Nette plugin](https://github.com/nette/claude-code):

```
/install-mcp-inspector
```

Or manually:

```bash
composer require nette/mcp-inspector
```

After installation, restart Claude Code session to activate the MCP server.

## Available Tools

### DI Container

| Tool | Description |
|------|-------------|
| `di_get_services` | List all registered services as name → type, filterable |
| `di_get_service` | Get details of a specific service (type, tags, instantiation status) |
| `di_get_parameter_names` | List all parameter names (nested values flattened to dotted notation) |
| `di_get_parameter` | Read a single parameter by name (sensitive masking, Windows path normalization) |
| `di_get_aliases` | List service aliases as alias → canonical name map |
| `di_find_by_tag` | Find services by tag |
| `di_find_by_type` | Find services implementing a type/interface (with autowired flag) |

### Database

| Tool | Description |
|------|-------------|
| `db_get_tables` | List all database tables |
| `db_get_columns` | Get columns of a specific table (types, nullable, primary key, foreign keys) |
| `db_get_relationships` | Get foreign key relationships between all tables (belongsTo, hasMany) |
| `db_get_indexes` | Get indexes for a table |
| `db_explain_query` | Run EXPLAIN on a SELECT query (read-only, safe) |
| `db_generate_entity` | Generate PHP entity class code for a table |

### Router

| Tool | Description |
|------|-------------|
| `router_get_routes` | List all registered routes with masks and defaults |
| `router_match_url` | Match URL to presenter/action (e.g., "/article/123") |
| `router_generate_url` | Generate URL for presenter/action (e.g., "Article:show") |

### Tracy Debugger

| Tool | Description |
|------|-------------|
| `tracy_get_last_exception` | Get last logged exception with details |
| `tracy_get_exceptions` | List recent exception files |
| `tracy_get_exception` | Get full details of a specific exception by HTML filename |
| `tracy_get_warnings` | Get recent PHP warnings |
| `tracy_get_log` | Get entries from any Tracy log level |

## Configuration

The inspector requires a single file in your project root: **`mcp-bootstrap.php`**.
It must `return` a `Closure` that produces a fully built `Nette\DI\Container`.
The script is responsible for requiring its own autoloader.

### Live config reload

The bridge invokes the closure on **every** tool call, so config edits in `common.neon`,
`services.neon`, etc. are picked up live — no need to restart Claude Code or the MCP
server. Nette caches the compiled container on disk and reuses it when configs are unchanged,
so the per-call overhead is just a metadata check and a `require`.

In auto-rebuild mode (debug), Nette also handles the long-running-process case where PHP
cannot redeclare an already-loaded class: when configs change, Nette generates a
uniquely-named copy of the new container into memory via `eval()` (gated behind
`autoRebuild=true`, never used in production). This requires `nette/di` ≥ 3.2 with the
live-reload patch.

If a rebuild fails (e.g. a typo in your config), the bridge keeps serving the
last-known-good container and adds a `_warning` field to tool results so you immediately
see the failure message and the container source.

### Standard skeleton

For Nette Web Project where `App\Bootstrap::boot()` is static:

```php
<?php declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
return fn() => App\Bootstrap::boot()->createContainer();
```

### Custom bootstrap

When `App\Bootstrap` has an instance constructor (multi-tenant, parameterized blogs etc.),
delegate to it through an instance — but keep the `new Configurator` call inside the
existing Bootstrap class so Nette's `%appDir%` autodetection (based on the file where
`new Configurator` is invoked) resolves correctly:

```php
<?php declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
return fn() => (new App\Bootstrap(App\Blog::LaTrine))->bootConsoleApplication();
```

### CLI flags

```
mcp-inspector [--project=PATH] [--bootstrap=PATH]
```

- `--project=PATH` — project root (defaults to current working directory)
- `--bootstrap=PATH` — alternate bootstrap script (defaults to `<project>/mcp-bootstrap.php`)

## Creating Custom Toolkits

Custom toolkits live in your project's code and are auto-discovered from the DI container.
Implement the `Toolkit` marker interface and register the class as a service:

```php
namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Nette\McpInspector\Toolkit;

class BlogToolkit implements Toolkit
{
    public function __construct(
        private BlogFacade $blogs,
    ) {}

    /**
     * Get blog post by ID.
     * @param int $id Post ID
     */
    #[McpTool(name: 'blog_get_post')]
    public function getPost(int $id): array
    {
        $post = $this->blogs->getPost($id);
        return $post ? ['id' => $post->id, 'title' => $post->title] : ['error' => 'not found'];
    }
}
```

```neon
# services.neon
services:
    - App\Mcp\BlogToolkit
```

`ServerFactory` finds it via `Container::findByType(Toolkit::class)` — no extra
registration needed. Standard Nette DI handles dependency injection.

### Overriding a built-in toolkit

A project toolkit registered via `services.neon` automatically overrides a
built-in of the same kind when it is `instanceof` it:

```php
namespace App\Mcp;

use Nette\McpInspector\Toolkits\DIToolkit;

// extends the built-in DIToolkit → replaces it entirely
class MyDIToolkit extends DIToolkit { /* extra tools or overrides */ }
```

`ServerFactory` registers project toolkits first, then skips any built-in whose
class is already covered by a project instance. Project toolkits that don't
extend a built-in (a fresh `implements Toolkit`) coexist with all defaults.

For built-in toolkits that ship with MCP Inspector itself, see
[CLAUDE.md](CLAUDE.md) — they take a `ContainerAccessor` (not a `Container`) and
re-resolve services per call so live config reload works. Project toolkits registered
via `services.neon` are constructed once at server startup; if you need config-reload
semantics for a project toolkit too, depend on `Nette\McpInspector\ContainerAccessor`
in its constructor and follow the built-in pattern.

## Standalone Usage

### CLI Mode

```bash
php vendor/bin/mcp-inspector --project=/path/to/project
```

### HTTP Mode

Copy `www/mcp-inspector.php` to your web root for HTTP-based MCP access.

### Manual MCP Configuration

If not using the Nette plugin, add to your project's `.mcp.json`:

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

`--project` is recommended over relying on the host's working directory — some MCP
clients spawn the server with an unpredictable cwd.

## TracyLogger — JSON Log Export (optional)

`TracyLogger` is a standalone Tracy logger for structured JSON output. **Not used by
MCP-Inspector itself** — opt-in for projects that want machine-readable logs:

```php
use Nette\McpInspector\TracyLogger;

// In Bootstrap.php or configuration
Tracy\Debugger::setLogger(new TracyLogger());
```

Logs are written to `log/mcp_telemetry.jsonl` in JSON Lines format:

```json
{"timestamp":"2025-01-16T10:30:00+00:00","level":"error","type":"exception","class":"RuntimeException","message":"...","file":"...","line":42}
```

Features:
- Structured JSON format for easy parsing
- Automatic sensitive data masking (passwords, tokens, API keys)
- File rotation (max 10MB, keeps 5 rotated files)
- Full exception details including stack trace

## Security

- All tools are **read-only** — no data modification
- Database tool only allows SELECT/SHOW/DESCRIBE queries
- Sensitive configuration values are automatically masked
- **Development only** — do not expose in production

## Requirements

- PHP 8.3+
- Nette Framework 3.2+
