# Nette MCP Inspector

MCP (Model Context Protocol) server for Nette application introspection. It lets AI assistants
such as Claude Code look into your application: its DI container, configuration, routing,
database schema and logs.

> ⚠️ **Development tool only.** The inspector exposes the DI graph, configuration and data of
> whatever application it is pointed at. Point it at development environments and development data only.

## Setup

```bash
composer require --dev nette/mcp-inspector
vendor/bin/mcp-inspector init
```

`init` creates three files and never overwrites an existing one:

| File | Purpose |
|---|---|
| `mcp-bootstrap.php` | returns your application's `Nette\Bootstrap\Configurator` (see below) |
| `config/mcp-inspector.neon` | the inspector's configuration: what the agent may do |
| `.mcp.json` | registers the `nette-inspector` server for Claude Code (an existing file is extended); `.cursor/mcp.json` and `.vscode/mcp.json` get the same entry when those directories exist |

Restart the Claude Code session afterwards. Use `--php="ddev exec php"` when PHP does not
run on the host, `--project=PATH` when the project is not the current directory.

### mcp-bootstrap.php

The file must `return` the `Configurator` with all configs added, *before* `createContainer()`
is called; the inspector builds the container itself. `init` recognizes the common shapes
of `App\Bootstrap`:

- **Static `App\Bootstrap::boot(): Configurator`** (the classic Web Project): `return App\Bootstrap::boot();`
- **Object `Bootstrap` with `bootWebApplication(): Container`** (Web Project since 2024): add a method
  that stops before creating the container, and use it from both places:

  ```php
  public function bootWebApplication(): Nette\DI\Container
  {
  	return $this->bootConfigurator()->createContainer();
  }

  public function bootConfigurator(): Configurator
  {
  	$this->initializeEnvironment();
  	$this->setupContainer();
  	return $this->configurator;
  }
  ```

  `mcp-bootstrap.php` is then `return (new App\Bootstrap)->bootConfigurator();`.
- **Custom bootstrap** (instance constructor, multi-tenant…): `init` writes a template with a TODO.
  Keep `new Configurator` inside your Bootstrap class so Nette's `%appDir%` autodetection (based on
  the file that instantiates it) stays correct. Environment variables from `.mcp.json` are a handy way
  to parameterize it:

  ```php
  $blog = getenv('BLOG') === 'phpfashion' ? App\Blog::PhpFashion : App\Blog::LaTrine;
  return (new App\Bootstrap($blog))->bootConsoleConfigurator();
  ```

The inspector switches the Configurator to debug mode itself (the CLI never autodetects it),
so it compiles its own container, separate from the web's cache. You are therefore looking at the
application as it runs in development: `%debugMode%` is true and services conditioned on it are
the development ones.

### config/mcp-inspector.neon

The inspector is itself a small Nette application: this file is the DI configuration of its own
container, with the usual `parameters:`, `services:` and one section per toolkit. Every section
is optional; a missing file means the defaults. This is the file `init` generates:

```neon
# Configuration of nette/mcp-inspector: a Nette DI config for the inspector's own container.
# The inspector reads it itself, do not add it to the application's configs.
# Every section is optional; missing keys use the defaults shown here.

inspector:
	# false keeps the inspector from starting at all
	enabled: true
	# tool names or patterns hidden from the agent, e.g. [db_*, tracy_get_log]
	disableTools: []
	# key names whose values are masked in results; whole words, camelCase and snake_case both match
	mask: [password, passwd, pass, pwd, secret, token, api_key, apikey, credential, credentials, auth, dsn]
	# text appended to the instructions the agent reads on connect, e.g. workflows of your custom toolkits
	instructions: ''
```

The inspector reads this file itself; do not add it to the application's configs. Changes take
effect after the MCP server restarts (Claude Code does that with the session).

### Protection against running in production

There is no reliable runtime signal in a CLI process that would tell the inspector it is
running on a production server, so the protection is layered instead:

1. install it as a `--dev` dependency: `composer install --no-dev` on the server never installs it;
2. the defaults are safe: nothing modifies data, secrets are masked (`inspector: mask:`), nothing writes files.
   The tools do run the application's code, as any debugging does: the router's filters, presenter classes,
   database queries; that is why the inspector belongs to development environments only;
3. an explicit kill switch: `inspector: enabled: false` in `config/mcp-inspector.neon` or the
   `MCP_INSPECTOR_DISABLED=1` environment variable makes the server refuse to start.

### Live config reload

`mcp-bootstrap.php` runs once. On every tool call the inspector asks the Configurator for the
container class: Nette checks the compiled container's dependencies (config files, touched
classes), recompiles it when they changed and returns a freshly named class, which the
inspector instantiates. When nothing changed, the same container instance is reused, so the
per-call overhead is a metadata check. Config edits in `common.neon`, `services.neon`, etc.
are therefore picked up live, without restarting Claude Code or the MCP server. This requires
`nette/di` ≥ 3.2.7.

If a rebuild fails (e.g. a typo in your config), the inspector keeps serving the last-known-good
container and adds a `_warning` field to tool results so you immediately see the failure message.

### CLI

```
mcp-inspector [options]                       starts the MCP server (stdio)
mcp-inspector init [options]                  generates the project files
mcp-inspector call <tool> [<json>] [options]  calls one tool and prints the result
```

`call` runs a tool exactly as the MCP client would, without a client, so you can try a tool or
check your bootstrap and configuration from the terminal.

- `--project=PATH` — project root (defaults to the current working directory)
- `--bootstrap=PATH` — bootstrap script (defaults to `<project>/mcp-bootstrap.php`)
- `--config=PATH` — inspector configuration (defaults to `<project>/config/mcp-inspector.neon`)
- `--php=COMMAND` — PHP command written to `.mcp.json` by `init` (defaults to `php`)

## Custom toolkits

A toolkit is a class implementing `Nette\McpInspector\Toolkit`; its public methods marked with
`#[McpTool]` become tools. Register it as a service in `config/mcp-inspector.neon`; it can depend
on `Nette\McpInspector\AppContainer`, whose `get()` returns the current application container
(so config reloads are honoured), or on any other inspector service. Mark a tool whose result carries
data from the application (database rows, user input) with `#[Nette\McpInspector\UntrustedData]`; the
inspector then tells the model not to follow instructions found in it. The tool's description comes from
the docblock, its `title` and `annotations` from `#[McpTool]`; a toolkit whose annotations depend on its
configuration implements `Nette\McpInspector\ToolAnnotator`. Return a small class with public properties
rather than an array: the client then receives an output schema derived from it (property types, the
constructor's `@param list<T>` docblocks, nested classes, enums, and `#[Schema]` on a constructor parameter
for formats, enums or ranges), and the model knows the shape of the result before calling. The inspector keeps
such classes in a `Schemas` namespace next to each toolkit (`Database\Schemas\QueryResult`):

```php
namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Nette\McpInspector\AppContainer;
use Nette\McpInspector\Toolkit;

class BlogToolkit implements Toolkit
{
	public function __construct(
		private AppContainer $app,
	) {}

	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * Get blog post by ID.
	 * @param int $id Post ID
	 */
	#[McpTool(name: 'blog_get_post', title: 'Blog post', annotations: new ToolAnnotations(readOnlyHint: true))]
	public function getPost(int $id): BlogPost
	{
		$post = $this->app->get()->getByType(BlogFacade::class)->getPost($id)
			?? throw new Mcp\Exception\ToolCallException("Post $id not found");
		return new BlogPost($post->id, $post->title);
	}
}

final class BlogPost
{
	public function __construct(
		public readonly int $id,
		public readonly string $title,
	) {}
}
```

```neon
# config/mcp-inspector.neon
services:
	- App\Mcp\BlogToolkit
```
