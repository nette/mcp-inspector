<?php declare(strict_types=1);

namespace Nette\McpInspector\Cli;

use Mcp\Exception\ToolCallException;
use Nette\CommandLine\Parser;
use Nette\McpInspector\Inspector;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use function array_slice, count, is_array;
use Nette\McpInspector\Server;


/**
 * The command line behind bin/mcp-inspector: `mcp-inspector [options]` starts the stdio server,
 * `init` generates the project files, `call <tool> [<json>]` runs one tool without an MCP client.
 */
final class Console
{
	private const Help = <<<'TXT'
		MCP Inspector - Nette application introspection over Model Context Protocol.

		Usage: mcp-inspector [options]                    starts the MCP server (stdio)
		       mcp-inspector init [options]               generates mcp-bootstrap.php, config/mcp-inspector.neon and .mcp.json
		       mcp-inspector call <tool> [<json>] [options]   calls one tool without an MCP client and prints the result

		Options:
		    -p, --project <path>      Path to project root (defaults to current directory)
		    -b, --bootstrap <path>    Bootstrap script returning Configurator (defaults to <project>/mcp-bootstrap.php)
		    -c, --config <path>       Inspector configuration (defaults to <project>/config/mcp-inspector.neon)
		    --php <command>           PHP command written to .mcp.json by init (defaults to php)
		    -h, --help                Show this help and exit

		The project must contain mcp-bootstrap.php that returns a configured
		Nette\Bootstrap\Configurator; `init` generates it. The script is responsible for requiring its own autoloader.
		TXT;


	/**
	 * @param  list<string>  $argv  as in $_SERVER['argv'], the script name first
	 * @return int  exit code
	 */
	public function run(array $argv): int
	{
		// the optional command and its arguments are positional and come first; nette/command-line handles options only
		$positional = [];
		while (isset($argv[1]) && !str_starts_with($argv[1], '-')) {
			$positional[] = array_splice($argv, 1, 1)[0];
		}
		$command = array_shift($positional);

		$cli = new Parser(self::Help);
		$args = $cli->parse(array_slice($argv, 1));
		$projectDir = $args['--project'] ?? getcwd();

		try {
			if ($args['--help']) {
				$cli->help();
				return 0;
			}

			return match ($command) {
				null => $this->serve($projectDir, $args),
				'init' => $this->init($projectDir, $args['--php'] ?? 'php'),
				'call' => $this->call($projectDir, $args, $positional),
				default => throw new \RuntimeException("Unknown command '$command'."),
			};
		} catch (\RuntimeException $e) { // configuration and bootstrap problems, reported without a stack trace
			fwrite(STDERR, $e->getMessage() . "\n");
			return 1;
		}
	}


	/**
	 * @param  array<string, mixed>  $args
	 */
	private function serve(string $projectDir, array $args): int
	{
		$this->createServer($projectDir, $args)->run();
		return 0;
	}


	private function init(string $projectDir, string $phpCommand): int
	{
		echo implode("\n", (new Installer($projectDir, $phpCommand))->install()), "\n";
		return 0;
	}


	/**
	 * @param  array<string, mixed>  $args
	 * @param  list<string>  $positional  tool name and its JSON arguments
	 */
	private function call(string $projectDir, array $args, array $positional): int
	{
		$tool = $positional[0] ?? throw new \RuntimeException('Usage: mcp-inspector call <tool> [<json arguments>]');
		try {
			$arguments = Json::decode($positional[1] ?? '{}', forceArrays: true);
		} catch (JsonException $e) {
			throw new \RuntimeException('Invalid JSON arguments: ' . $e->getMessage());
		}
		if (!is_array($arguments) || count($positional) > 2) {
			throw new \RuntimeException('Usage: mcp-inspector call <tool> [<json object>]');
		}

		try {
			$result = $this->createServer($projectDir, $args)->call($tool, $arguments);
		} catch (ToolCallException | \InvalidArgumentException $e) {
			$result = ['error' => $e->getMessage()];
		}

		echo Json::encode($result, pretty: true), "\n";
		return isset($result['error']) ? 1 : 0;
	}


	/**
	 * @param  array<string, mixed>  $args
	 */
	private function createServer(string $projectDir, array $args): Server
	{
		if (getenv('MCP_INSPECTOR_DISABLED')) { // the kill switch comes before anything of the project runs
			throw new \RuntimeException('MCP Inspector is disabled: the MCP_INSPECTOR_DISABLED environment variable is set.');
		}

		$server = Inspector::createServer(
			projectDir: $projectDir,
			bootstrapPath: $args['--bootstrap'] ?? 'mcp-bootstrap.php',
			configFile: $args['--config'] ?? null,
		);
		if (!$server->isEnabled()) {
			throw new \RuntimeException('MCP Inspector is disabled: `inspector: enabled: false` in ' . Inspector::ConfigFile . '.');
		}

		return $server;
	}
}
