<?php declare(strict_types=1);

namespace Nette\McpInspector\Cli;

use Mcp\Exception\ToolCallException;
use Nette\CommandLine\Attributes as Cli;
use Nette\CommandLine\Command;
use Nette\CommandLine\Console;
use Nette\CommandLine\HelpRenderer;
use Nette\CommandLine\ParseException;
use Nette\CommandLine\Parser;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Server;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use function is_array;


/**
 * The command line behind bin/mcp-inspector: `mcp-inspector [options]` starts the stdio server,
 * `init` generates the project files, `call <tool> [<json>]` runs one tool without an MCP client.
 */
final class Application
{
	/** one console per stream, so a redirected stdout does not decide the colors of stderr */
	private readonly Console $out;
	private readonly Console $err;


	public function __construct()
	{
		$this->out = new Console(STDOUT);
		$this->err = new Console(STDERR);
	}


	/**
	 * @param  list<string>  $argv  as in $_SERVER['argv'], the script name first
	 * @return int  exit code
	 */
	public function run(array $argv): int
	{
		try {
			$args = (new Parser)->parse($this->defineCommandLine(), array_slice($argv, 1));
			if ($args['--help']) {
				(new HelpRenderer($this->out))->render($args->command);
				return 0;
			}

			return $args->run();

		} catch (ParseException|\RuntimeException $e) { // a wrong command line, configuration and bootstrap problems, reported without a stack trace
			$this->err->writeLine($e->getMessage(), 'red');
			return 1;
		}
	}


	private function defineCommandLine(): Command
	{
		$program = Command::from($this->serve(...));
		$program->addFlag('--help', 'Show this help and exit', alias: '-h', standalone: true);
		$program->addCommandFrom($this->init(...));
		$program->addCommandFrom($this->call(...));
		$program->addText("Without a command it starts the MCP server (stdio). The project must contain mcp-bootstrap.php\nthat returns a configured Nette\\Bootstrap\\Configurator; `init` generates it. The script is responsible\nfor requiring its own autoloader.");
		return $program;
	}


	#[Cli\Command(
		'MCP Inspector - Nette application introspection over Model Context Protocol.',
		usage: 'mcp-inspector [<command>] [options]',
		name: 'mcp-inspector',
	)]
	private function serve(
		#[Cli\Option('Path to project root (defaults to current directory)', alias: '-p', valueName: 'path')]
		?string $project = null,
		#[Cli\Option('Bootstrap script returning Configurator (defaults to <project>/mcp-bootstrap.php)', alias: '-b', valueName: 'path')]
		?string $bootstrap = null,
		#[Cli\Option('Inspector configuration (defaults to <project>/config/mcp-inspector.neon)', alias: '-c', valueName: 'path')]
		?string $config = null,
	): int
	{
		$this->createServer($project, $bootstrap, $config)->run();
		return 0;
	}


	#[Cli\Command('generates mcp-bootstrap.php, config/mcp-inspector.neon and .mcp.json')]
	private function init(
		?string $project,
		#[Cli\Option('PHP command written to .mcp.json (defaults to php)', valueName: 'command')]
		?string $php = null,
	): int
	{
		foreach ((new Installer($project ?? (string) getcwd(), $php ?? 'php'))->install() as $message) {
			$this->out->writeLine($message);
		}

		return 0;
	}


	#[Cli\Command('calls one tool without an MCP client and prints the result')]
	private function call(
		?string $project,
		?string $bootstrap,
		?string $config,
		#[Cli\Argument('name of the tool')]
		string $tool,
		#[Cli\Argument('arguments of the tool as a JSON object')]
		?string $json = null,
	): int
	{
		try {
			$arguments = Json::decode($json ?? '{}', forceArrays: true);
		} catch (JsonException $e) {
			throw new \RuntimeException('Invalid JSON arguments: ' . $e->getMessage());
		}
		if (!is_array($arguments)) {
			throw new \RuntimeException('Usage: mcp-inspector call <tool> [<json object>]');
		}

		try {
			$result = $this->createServer($project, $bootstrap, $config)->call($tool, $arguments);
		} catch (ToolCallException|\InvalidArgumentException $e) {
			$result = ['error' => $e->getMessage()];
		}

		$this->out->writeLine(Json::encode($result, pretty: true));
		return isset($result['error']) ? 1 : 0;
	}


	private function createServer(?string $project, ?string $bootstrap, ?string $config): Server
	{
		if (getenv('MCP_INSPECTOR_DISABLED')) { // the kill switch comes before anything of the project runs
			throw new \RuntimeException('MCP Inspector is disabled: the MCP_INSPECTOR_DISABLED environment variable is set.');
		}

		$server = Inspector::createServer(
			projectDir: $project ?? (string) getcwd(),
			bootstrapPath: $bootstrap ?? 'mcp-bootstrap.php',
			configFile: $config,
		);
		if (!$server->isEnabled()) {
			throw new \RuntimeException('MCP Inspector is disabled: `inspector: enabled: false` in ' . Inspector::ConfigFile . '.');
		}

		return $server;
	}
}
