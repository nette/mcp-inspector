<?php declare(strict_types=1);

namespace Nette\McpInspector\Application;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Nette\Database\Connection;
use Nette\McpInspector\AppContainer;
use Nette\McpInspector\Toolkit;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tracy\ILogger;
use Tracy\Logger;
use function is_array, is_string;
use Nette\McpInspector\Application\Schemas\AppInfo;


/**
 * Facts about the inspected application as a whole: versions, installed Nette packages, paths, database driver.
 */
class ApplicationToolkit implements Toolkit
{
	public function __construct(
		private readonly AppContainer $app,
		private readonly string $projectDir,
	) {
	}


	public function isAvailable(): bool
	{
		return true;
	}


	/**
	 * Get the application's PHP and Nette versions, installed Nette packages, directories and database driver.
	 * Call this first in a conversation so the code you write matches the versions in use.
	 */
	#[McpTool(
		name: 'app_get_info',
		title: 'Application info',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getInfo(): AppInfo
	{
		$container = $this->app->get();
		$packages = $this->installedPackages();

		$directories = [];
		try {
			$parameters = $container->getParameters();
		} catch (\Throwable) {
			$parameters = []; // parameters may not be exported (di › export › parameters)
		}
		foreach (['rootDir', 'appDir', 'wwwDir', 'tempDir', 'vendorDir'] as $key) {
			if (is_string($parameters[$key] ?? null)) {
				$directories[$key] = FileSystem::unixSlashes($parameters[$key]);
			}
		}
		$logger = interface_exists(ILogger::class) ? $container->getByType(ILogger::class, throw: false) : null;
		if ($logger instanceof Logger && $logger->directory !== null) {
			$directories['logDir'] = FileSystem::unixSlashes($logger->directory);
		}

		$connection = class_exists(Connection::class) ? $container->getByType(Connection::class, throw: false) : null;

		return new AppInfo(
			php: PHP_VERSION,
			nette: $packages['nette/application'] ?? $packages['nette/di'] ?? null,
			packages: $packages,
			directories: $directories,
			database: $connection === null ? null : explode(':', $connection->getDsn(), 2)[0],
		);
	}


	/**
	 * Versions of the Nette family packages from composer.lock, name => version.
	 * @return array<string, string>
	 */
	private function installedPackages(): array
	{
		$file = $this->projectDir . '/composer.lock';
		if (!is_file($file)) {
			return [];
		}

		$lock = Json::decode(FileSystem::read($file), forceArrays: true);
		$packages = [];
		foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
			if (is_array($package) && preg_match('~^(nette|latte|tracy|texy|contributte)/~', $package['name'] ?? '')) {
				$packages[$package['name']] = $package['version'];
			}
		}
		ksort($packages);

		return $packages;
	}
}
