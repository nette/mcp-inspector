<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Composer\InstalledVersions;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\Neon\Exception;


/**
 * Entry point: builds the inspector's own DI container from config/mcp-inspector.neon (every toolkit is
 * a compiler extension with its config section) and returns the Server, which collects the Toolkit services.
 */
final class Inspector
{
	public const ConfigFile = 'config/mcp-inspector.neon';


	public static function createServer(
		string $projectDir,
		string $bootstrapPath = 'mcp-bootstrap.php',
		?string $configFile = null,
	): Server
	{
		return self::createContainer($projectDir, $bootstrapPath, $configFile)->getByType(Server::class);
	}


	public static function createContainer(
		string $projectDir,
		string $bootstrapPath = 'mcp-bootstrap.php',
		?string $configFile = null,
	): Container
	{
		if ($configFile !== null && !is_file($configFile)) {
			throw new \RuntimeException("Inspector configuration not found: $configFile");
		}
		$configFile ??= "$projectDir/" . self::ConfigFile;

		$tempDir = is_dir("$projectDir/temp")
			? "$projectDir/temp/mcp-inspector"
			: sys_get_temp_dir() . '/mcp-inspector-' . hash('xxh128', $projectDir); // do not create directories in the project
		$loader = new ContainerLoader($tempDir, autoRebuild: true);
		$class = $loader->load(function (Compiler $compiler) use ($projectDir, $bootstrapPath, $configFile): ?string {
			$compiler->addConfig(['parameters' => ['projectDir' => $projectDir]]);
			$compiler->addExtension('inspector', new InspectorExtension($projectDir, $bootstrapPath));
			$compiler->addExtension('application', new Application\ApplicationExtension);
			$compiler->addExtension('presenters', new Presenters\PresentersExtension);
			$compiler->addExtension('router', new Router\RouterExtension);
			if (is_file($configFile)) {
				try {
					$compiler->loadConfig($configFile);
				} catch (Exception $e) {
					throw new \RuntimeException("Invalid $configFile: " . $e->getMessage(), 0, $e);
				}
			} else {
				$compiler->addDependencies([$configFile]); // recompile once the file appears
			}
			return null;
		}, [$projectDir, $bootstrapPath, $configFile, self::version()]);

		return new $class;
	}


	/**
	 * Identifies the inspector's code for the container cache key: the installed reference, or a fingerprint
	 * of the source files when running from source, so an edited toolkit recompiles the container.
	 */
	private static function version(): string
	{
		try {
			$reference = InstalledVersions::getReference('nette/mcp-inspector');
			if ($reference !== null) {
				return $reference;
			}
		} catch (\OutOfBoundsException) {
			// running from source
		}

		$fingerprint = [];
		foreach ([...glob(__DIR__ . '/*.php') ?: [], ...glob(__DIR__ . '/*/*.php') ?: []] as $file) {
			$fingerprint[] = filemtime($file);
		}

		return md5(implode(',', $fingerprint));
	}
}
