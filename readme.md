# Nette MCP Inspector

MCP (Model Context Protocol) server for Nette application introspection. Allows AI assistants to inspect your Nette application's DI container, database schema, routing, and more.

<img width="2816" height="1406" alt="image" src="https://github.com/user-attachments/assets/35a314bb-065b-486f-8e58-972bdb45516c" />

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
| `di_get_services` | List all registered services with types and autowiring info |
| `di_get_service` | Get details of a specific service (factory, setup calls, tags) |

### Database

| Tool | Description |
|------|-------------|
| `db_get_tables` | List all database tables |
| `db_get_columns` | Get columns of a specific table (types, nullable, primary key, foreign keys) |
| `db_get_relationships` | Get foreign key relationships between all tables (belongsTo, hasMany) |

### Router

| Tool | Description |
|------|-------------|
| `router_get_routes` | List all registered routes with masks and defaults |
| `router_match_url` | Match URL to presenter/action (e.g., "/article/123") |
| `router_generate_url` | Generate URL for presenter/action (e.g., "Article:show") |

## Configuration

Create `mcp-config.neon` in your project root (optional):

```neon
# Path to Bootstrap file (defaults to app/Bootstrap.php)
bootstrap: app/Bootstrap.php

# Bootstrap class name (defaults to App\Bootstrap)
bootstrapClass: App\Bootstrap

# Custom toolkits
toolkits:
    - App\Mcp\MyCustomToolkit
```

## Creating Custom Toolkits

```php
use Mcp\Capability\Attribute\McpTool;
use Nette\McpInspector\Toolkit;
use Nette\McpInspector\Bridge\BootstrapBridge;

class MyToolkit implements Toolkit
{
    public function __construct(
        private BootstrapBridge $bridge,
    ) {}

    /**
     * Tool description from PHPDoc.
     * @param string $param Parameter description
     */
    #[McpTool(name: 'my_tool')]
    public function myMethod(string $param): array
    {
        return ['result' => 'data'];
    }
}
```

Register in `mcp-config.neon`:

```neon
toolkits:
    - App\Mcp\MyToolkit
```

## Standalone Usage

### CLI Mode

```bash
php vendor/bin/mcp-inspector
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
            "args": ["vendor/bin/mcp-inspector"]
        }
    }
}
```

## Requirements

- PHP 8.2+
- Nette Framework 3.2+
