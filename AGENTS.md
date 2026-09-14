# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Project Overview

`nette/mcp-inspector` is an MCP (Model Context Protocol) server for Nette application introspection: it lets AI assistants inspect a Nette application's DI container, configuration, routing, database schema and logs. Development tool only, installed as a `--dev` dependency.

- **PHP**: 8.3 - 8.5
- **Tests**: `vendor/bin/tester tests -s -C`; **static analysis**: `composer phpstan` (level 8)
- Every file starts with `declare(strict_types=1);`, Nette Coding Standard, two blank lines between methods.

## Architecture

The inspector is itself a small Nette application built on `nette/di`: `config/mcp-inspector.neon`
in the inspected project is the DI configuration of the **inspector's own container**, and every
toolkit is a compiler extension with its config section. There are therefore two containers in
one process: the inspector's (compiled once at startup into `<project>/temp/mcp-inspector`, or into the system temp directory when the project has no `temp/`) and
the inspected application's (built and rebuilt by `AppContainer`).

- **`bin/mcp-inspector`** — entry script: registers the inspector's autoloader first and the project's last (Composer prepends autoloaders, the last one wins), so the project's Nette libraries are the ones loaded, then hands over to `Cli\Application`: `mcp-inspector [options]` starts the stdio server, `mcp-inspector init` generates the project files (`Cli\Installer`), `mcp-inspector call <tool> [<json>]` runs one tool through `Server::call()` and prints JSON (for trying tools and configurations without a client); options `--project`, `--bootstrap`, `--config`, `--php`. `src/McpInspector/Cli/` holds everything that serves the command line only; the core stays in `src/McpInspector/`. Output goes through a `Nette\CommandLine\Console` per stream (`$out`, `$err`), never `echo`: while the server runs, stdout carries the MCP protocol, so anything a person reads that is not the result of a command belongs on stderr.
- **`Inspector::createContainer()`** — compiles the inspector container: `parameters: projectDir`, the `inspector` extension, one extension per toolkit, then `config/mcp-inspector.neon` if it exists (otherwise the file is registered as a dependency so its creation triggers a recompile).
- **`InspectorExtension`** — section `inspector:` (`enabled`, `disableTools`, `mask`, `instructions` appended to the server instructions, the place for workflows of custom toolkits); registers `AppContainer`, `Masking` (the configured secret key names; toolkits get it injected and use `shouldMask()` for keys and `maskText()` for free text such as log lines and exception reports), the SDK's `ReferenceHandler`, `ToolCallHandler` and `Server`, and in `beforeCompile()` hands the Server every service implementing `Toolkit`.
- **`AppContainer`** — the inspected application's container. Runs `mcp-bootstrap.php` once (it must return a `Nette\Bootstrap\Configurator`), forces `setDebugMode(true)` (the CLI never autodetects it; auto-rebuild is what makes reload possible) and on every `get()` asks `Configurator::loadContainer()` for the container class: same class → same instance, new class → `createContainer()`. A failed rebuild keeps the last valid container and exposes the error via `getLastError()`; a failure on the first call is rethrown.
- **`Server`** — collects `#[McpTool]` methods of the toolkits whose `isAvailable()` is true, minus `disableTools`, and runs the MCP SDK server over stdio; `call()` runs one tool through the same registry and handler, validating the arguments against the tool's input schema. Tool handlers are first-class callables (`$method->getClosure($toolkit)`), so the SDK derives input schemas from the method signatures; the SDK reads nothing else from a closure, so the Server passes `title`, `annotations` (from `#[McpTool]`) and the description (from the docblock) explicitly, and a toolkit implementing `ToolAnnotator` may adjust the annotations by its configuration (`db_query` is read-only unless `database: readOnly: false`). The SDK understands only `T[]` / `array<T>` docblocks: an `array` parameter of any other shape needs `#[Schema(definition: …)]`, and `tests/bootstrap.php::assertToolsComplete()` guards every toolkit against the metadata a client relies on.
- **`ToolCallHandler`** — runs every tool call (from the SDK and from `Server::call()`): converts throwables into `ToolCallException` (an error result the model can react to), turns a result object into the array the client will see as JSON, adds `_notice` to results of `#[UntrustedData]` tools and `_warning` when `AppContainer::getLastError()` is set.
- **Results are classes, not arrays** — every tool returns a small final class with public readonly promoted properties, kept in the toolkit's `Schemas` namespace in one `schemas.php` per toolkit (`Database\Schemas\QueryResult`, `Tracy\Schemas\Log`, nested ones like `Database\Schemas\Column`; `composer.json` has a classmap for that). `OutputSchema::forClass()` derives the tool's `outputSchema` from the class: property types, the constructor's `@param list<T>` / `array<string, T>` docblocks (type and description), nested classes, backed enums, and `#[Schema]` on the constructor parameter for what a type cannot say (format, enum, ranges; the SDK's attribute targets parameters, not properties). The schema therefore cannot drift from the data. Toolkit tests call the methods directly and read properties; `Server::call()` returns the array form.
- **`Toolkit`** — interface: `isAvailable(): bool` plus `#[McpTool]` methods. Toolkits depend on accessors to *their* objects (`ExplorerAccessor`, `RouterAccessor`, `PresenterAccessor`…), never on the whole application container; only the DI toolkit legitimately depends on `AppContainer`, the container being its subject. Accessors resolve through `AppContainer::get()` on every call, so reloads are honoured; `ExplorerAccessor::get()` also pings the connection and reconnects a dead one, because the stdio process lives for hours and database servers drop idle connections.
- **`Presenters\PresenterToolkit`** — the mapping is read from `PresenterFactory`'s private property (no getter before nette/application 3.3); presenters are found on disk (`*Presenter.php` in the Composer PSR-4 directories of the mapped namespaces and in `%appDir%`), and the presenter name is recovered by inverting the mapping mask (`unformatPresenterClass()`).
- **`Installer`** — behind `init`: writes `mcp-bootstrap.php` (recognizing the shape of `App\Bootstrap`), `config/mcp-inspector.neon` with the defaults spelled out, and the `nette-inspector` entry in `.mcp.json`; never overwrites.

### Adding a toolkit

1. A directory per domain (`src/McpInspector/Database/`…) with the toolkit, its accessor and its extension.
2. The extension declares its section with `Expect::structure()` and registers the accessor and the toolkit as services, handing each the config values it needs as constructor arguments (no config objects, no service locator).
3. Register the extension in `Inspector::createContainer()`.
4. `isAvailable()` delegates to the accessor; the Server leaves unavailable toolkits out silently.
5. Tools return arrays; no toolkit needs to know about `_warning` or `_notice`, `ToolCallHandler` adds them.
6. A tool whose result carries data from the application (log lines, database rows, user input) is marked `#[UntrustedData]`: the handler appends a `_notice` telling the model not to follow instructions found in it. Mask secrets in the tool itself (`Masking`).

### Protection

There is no runtime production detection: in a CLI process Nette's debug-mode autodetection always says "production" (it compares the hostname against the IP list). Protection is layered instead: `--dev` dependency, safe defaults (read-only, masking, no code execution), and the kill switch `inspector: enabled: false` / `MCP_INSPECTOR_DISABLED`.

## Testing

`tests/bootstrap.php` provides `getTempDir()` and `createTestProject($config)`, which writes a throwaway project (`mcp-bootstrap.php` + `config.neon`) so tests run against a real `Configurator`, real compilation and real reloads. When a test rewrites a config file, bump its mtime (`touch($file, $mtime += 10)`): Nette's `DependencyChecker` compares mtimes and two writes within one second would look unchanged.
