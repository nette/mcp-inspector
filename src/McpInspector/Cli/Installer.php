<?php declare(strict_types=1);

namespace Nette\McpInspector\Cli;

use Nette\McpInspector\Inspector;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use function count, strlen;


/**
 * Generates the project files the inspector needs: mcp-bootstrap.php, config/mcp-inspector.neon
 * and the server entry in .mcp.json. Existing files are left untouched, so it is safe to run again.
 */
final class Installer
{
	/** the generated config/mcp-inspector.neon, spelling out the defaults */
	public const ConfigTemplate = <<<'NEON'
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

		NEON;

	private const ServerName = 'nette-inspector';


	public function __construct(
		private readonly string $projectDir,
		private readonly string $phpCommand = 'php',
		private readonly string $bootstrapClass = 'App\Bootstrap',
	) {
	}


	/**
	 * Returns a message per file describing what was done.
	 * @return list<string>
	 */
	public function install(): array
	{
		return [
			$this->writeBootstrap(),
			$this->writeConfig(),
			...$this->writeEditorConfigs(),
		];
	}


	private function writeBootstrap(): string
	{
		$file = $this->projectDir . '/mcp-bootstrap.php';
		if (is_file($file)) {
			return 'mcp-bootstrap.php already exists, left untouched.';
		}

		[$expression, $note] = $this->detectBootstrap();
		FileSystem::write($file, <<<PHP
			<?php declare(strict_types=1);

			// Bootstrap for nette/mcp-inspector: returns the application's Configurator with all configs added,
			// without creating the container. The inspector switches on debug mode itself.
			$note
			require __DIR__ . '/vendor/autoload.php';

			return $expression;

			PHP);

		return 'mcp-bootstrap.php created' . ($note ? ', please complete it (see the comment inside)' : '') . '.';
	}


	/**
	 * Recognizes the common shapes of App\Bootstrap and returns the PHP expression producing the Configurator.
	 * @return array{string, string}  expression, note for the user
	 */
	private function detectBootstrap(): array
	{
		$class = $this->bootstrapClass;
		if (!class_exists($class)) {
			return [
				"(new $class)->bootConfigurator() /* TODO: class $class not found, adjust to your project */",
				"// TODO: the inspector could not find $class; return a Nette\\Bootstrap\\Configurator here.\n",
			];
		}

		$rc = new \ReflectionClass($class);
		if (
			$rc->hasMethod('boot')
			&& $rc->getMethod('boot')->isStatic()
			&& (string) $rc->getMethod('boot')->getReturnType() === 'Nette\Bootstrap\Configurator'
		) {
			return ["$class::boot()", ''];
		}

		$constructible = !($rc->getConstructor()?->getNumberOfRequiredParameters());
		if ($constructible && $rc->hasMethod('bootConfigurator')) {
			return ["(new $class)->bootConfigurator()", ''];
		}

		return [
			"(new $class)->bootConfigurator() /* TODO */",
			"// TODO: $class has no method returning the Configurator. Add one, e.g.\n"
			. "//     public function bootConfigurator(): Configurator { \$this->initializeEnvironment(); \$this->setupContainer(); return \$this->configurator; }\n"
			. "// and make bootWebApplication() use it: return \$this->bootConfigurator()->createContainer();\n",
		];
	}


	private function writeConfig(): string
	{
		$file = $this->projectDir . '/' . Inspector::ConfigFile;
		if (is_file($file)) {
			return Inspector::ConfigFile . ' already exists, left untouched.';
		}

		FileSystem::write($file, self::ConfigTemplate);

		return Inspector::ConfigFile . ' created.';
	}


	/**
	 * Registers the server for Claude Code (.mcp.json, always) and for Cursor and VS Code when their
	 * directories exist in the project.
	 * @return list<string>
	 */
	private function writeEditorConfigs(): array
	{
		$messages = [$this->writeEditorConfig('.mcp.json', 'mcpServers')];
		if (is_dir($this->projectDir . '/.cursor')) {
			$messages[] = $this->writeEditorConfig('.cursor/mcp.json', 'mcpServers');
		}
		if (is_dir($this->projectDir . '/.vscode')) {
			$messages[] = $this->writeEditorConfig('.vscode/mcp.json', 'servers');
		}

		return $messages;
	}


	private function writeEditorConfig(string $name, string $key): string
	{
		$file = $this->projectDir . '/' . $name;
		$data = is_file($file) ? Json::decode(FileSystem::read($file), forceArrays: true) : [];
		if (isset($data[$key][self::ServerName])) {
			return "$name already contains the " . self::ServerName . ' server, left untouched.';
		}

		$data[$key][self::ServerName] = [
			'type' => 'stdio',
			'command' => $this->phpCommand,
			'args' => [$this->relativeBinPath()],
		];
		$json = preg_replace_callback('~^(?: {4})+~m', fn($m) => str_repeat('  ', intdiv(strlen($m[0]), 4)), Json::encode($data, pretty: true)); // two-space indent, as editors write it
		FileSystem::write($file, $json . "\n");

		return "$name: " . self::ServerName . ' server ' . (count($data[$key]) > 1 ? 'added' : 'created') . '.';
	}


	private function relativeBinPath(): string
	{
		$bin = FileSystem::unixSlashes(FileSystem::normalizePath(__DIR__ . '/../../../bin/mcp-inspector'));
		$project = FileSystem::unixSlashes(FileSystem::normalizePath($this->projectDir)) . '/';
		return str_starts_with($bin, $project)
			? substr($bin, strlen($project))
			: $bin;
	}
}
