<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Composer\InstalledVersions;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server as McpServer;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionClass;
use ReflectionMethod;


/**
 * MCP server orchestrator with dual transport support.
 *
 * For HTTP transport, pass PSR-17 factories to the constructor:
 * `new Server($responseFactory, $streamFactory)`. CLI transport requires nothing.
 */
class Server
{
	private const Instructions = <<<'TXT'
		Read-only introspection of a Nette PHP application. Use these tools to answer
		"what is wired into this app?" — never to mutate state.

		Tool selection guide:
		- Looking for a service by interface or class? Use `di_find_by_type` first; only
		  fall back to `di_get_services` (with optional `filter`) for fuzzy/name-based search.
		- Need a config value? `di_get_parameter_names` lists all keys (dotted notation for
		  nested values), then `di_get_parameter` reads a single value. Sensitive keys are
		  masked automatically — do not assume the value is the real secret.
		- Database schema questions: start with `db_get_tables`, then `db_get_columns` for
		  the specific table. `db_get_relationships` covers FK graph in one call.
		- "Which presenter handles URL X?" → `router_match_url`. The reverse direction
		  (build a URL from presenter:action) → `router_generate_url`.
		- App misbehaving? `tracy_get_last_exception` for the freshest crash;
		  `tracy_get_log` for warnings/errors at a chosen level.

		Results may carry a `_warning` field — that means a config rebuild failed and you
		are looking at the last-known-good container. Tell the user.

		This server is for development/staging environments only. Do not invoke against
		a production app — it exposes the full DI graph including (best-effort masked)
		secrets.
		TXT;

	/** @var Toolkit[] */
	private array $toolkits = [];


	public function __construct(
		private ?ResponseFactoryInterface $responseFactory = null,
		private ?StreamFactoryInterface $streamFactory = null,
	) {
	}


	public function addToolkit(Toolkit $toolkit): self
	{
		$this->toolkits[] = $toolkit;
		return $this;
	}


	/**
	 * Returns true if any registered toolkit is an instance of the given class/interface.
	 * Used by ServerFactory to let project toolkits override built-ins.
	 */
	public function hasToolkitOfType(string $class): bool
	{
		foreach ($this->toolkits as $toolkit) {
			if ($toolkit instanceof $class) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Run with stdio transport (CLI).
	 */
	public function runCli(): void
	{
		$this->build()->run(new StdioTransport);
	}


	/**
	 * Run with HTTP transport, returns PSR-7 Response.
	 * Requires PSR-17 factories passed to the constructor.
	 */
	public function runHttp(ServerRequestInterface $request): ResponseInterface
	{
		if ($this->responseFactory === null || $this->streamFactory === null) {
			throw new \LogicException(
				'Server::runHttp() requires PSR-17 ResponseFactoryInterface and StreamFactoryInterface to be passed to the constructor.',
			);
		}

		$transport = new StreamableHttpTransport($request, $this->responseFactory, $this->streamFactory);
		return $this->build()->run($transport);
	}


	private function build(): McpServer
	{
		$builder = McpServer::builder()
			->setServerInfo('Nette MCP Inspector', $this->resolveVersion())
			->setInstructions(self::Instructions);

		foreach ($this->toolkits as $toolkit) {
			$rc = new ReflectionClass($toolkit);
			foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				$attrs = $method->getAttributes(McpTool::class);
				if (!$attrs) {
					continue;
				}
				$attr = $attrs[0]->newInstance();
				$name = $method->getName();
				$builder->addTool(
					handler: $toolkit->$name(...),
					name: $attr->name ?? $name,
				);
			}
		}

		return $builder->build();
	}


	private function resolveVersion(): string
	{
		if (class_exists(InstalledVersions::class)) {
			try {
				return InstalledVersions::getPrettyVersion('nette/mcp-inspector') ?? 'dev';
			} catch (\OutOfBoundsException) {
				// package not installed via composer (running from source) — fall through
			}
		}
		return 'dev';
	}
}
