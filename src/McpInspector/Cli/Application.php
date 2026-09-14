<?php declare(strict_types=1);

namespace Nette\McpInspector\Cli;

use Mcp\Exception\ToolCallException;
use Nette\CommandLine\Command;
use Nette\CommandLine\Console;
use Nette\CommandLine\HelpRenderer;
use Nette\CommandLine\ParseException;
use Nette\CommandLine\Parser;
use Nette\CommandLine\ParseResult;
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
			$args = (new Parser)->parse(self::defineCommandLine(), array_slice($argv, 1));
			if ($args['--help']) {
				(new HelpRenderer($this->out))->render($args->command);
				return 0;
			}

			$projectDir = $args['--project'] ?? getcwd();
			return match ($args->command->getFullName()) {
				'' => $this->serve($projectDir, $args),
				'init' => $this->init($projectDir, $args['--php'] ?? 'php'),
				'call' => $this->call($projectDir, $args, $args['tool'], $args['json']),
				default => throw new \LogicException("Command '{$args->command->getFullName()}' has no handler."),
			};
		} catch (ParseException|\RuntimeException $e) { // a wrong command line, configuration and bootstrap problems, reported without a stack trace
			$this->err->writeLine($e->getMessage(), 'red');
			return 1;
		}
	}


	private static function defineCommandLine(): Command
	{
		$program = new Command('mcp-inspector', usage: 'mcp-inspector [<command>] [options]');
		$program->addText('MCP Inspector - Nette application introspection over Model Context Protocol.');
		$program->addOption('--project', 'Path to project root (defaults to current directory)', alias: '-p', valueName: 'path');
		$program->addOption(
			'--bootstrap',
			'Bootstrap script returning Configurator (defaults to <project>/mcp-bootstrap.php)',
			alias: '-b',
			valueName: 'path',
		);
		$program->addOption(
			'--config',
			'Inspector configuration (defaults to <project>/config/mcp-inspector.neon)',
			alias: '-c',
			valueName: 'path',
		);
		$program->addFlag('--help', 'Show this help and exit', alias: '-h', standalone: true);

		$init = $program->addCommand('init', 'generates mcp-bootstrap.php, config/mcp-inspector.neon and .mcp.json');
		$init->addOption('--php', 'PHP command written to .mcp.json (defaults to php)', valueName: 'command');

		$call = $program->addCommand('call', 'calls one tool without an MCP client and prints the result');
		$call->addArgument('tool', 'name of the tool');
		$call->addArgument('json', 'arguments of the tool as a JSON object', optional: true);

		$program->addText("Without a command it starts the MCP server (stdio). The project must contain mcp-bootstrap.php\nthat returns a configured Nette\\Bootstrap\\Configurator; `init` generates it. The script is responsible\nfor requiring its own autoloader.");
		return $program;
	}


	private function serve(string $projectDir, ParseResult $args): int
	{
		$this->createServer($projectDir, $args)->run();
		return 0;
	}


	private function init(string $projectDir, string $phpCommand): int
	{
		foreach ((new Installer($projectDir, $phpCommand))->install() as $message) {
			$this->out->writeLine($message);
		}

		return 0;
	}


	private function call(string $projectDir, ParseResult $args, string $tool, ?string $json): int
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
			$result = $this->createServer($projectDir, $args)->call($tool, $arguments);
		} catch (ToolCallException|\InvalidArgumentException $e) {
			$result = ['error' => $e->getMessage()];
		}

		$this->out->writeLine(Json::encode($result, pretty: true));
		return isset($result['error']) ? 1 : 0;
	}


	private function createServer(string $projectDir, ParseResult $args): Server
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
