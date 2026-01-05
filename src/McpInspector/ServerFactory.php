<?php

declare(strict_types=1);

namespace Nette\McpInspector;

use Nette\McpInspector\Bridge\BootstrapBridge;
use Nette\McpInspector\Toolkits\DatabaseToolkit;
use Nette\McpInspector\Toolkits\DIToolkit;
use Nette\McpInspector\Toolkits\RouterToolkit;
use Nette\Neon\Neon;


/**
 * Factory for creating configured MCP server instances.
 */
class ServerFactory
{
	public static function create(?string $configPath = null): Server
	{
		$configPath ??= getcwd() . '/mcp-config.neon';
		$config = file_exists($configPath) ? Neon::decodeFile($configPath) : [];

		$bridge = new BootstrapBridge(
			$config['bootstrap'] ?? 'app/Bootstrap.php',
			$config['bootstrapClass'] ?? 'App\Bootstrap',
		);

		$server = new Server;

		// Built-in toolkits
		$toolkits = [
			DIToolkit::tryCreate($bridge),
			DatabaseToolkit::tryCreate($bridge),
			RouterToolkit::tryCreate($bridge),
		];
		foreach (array_filter($toolkits) as $toolkit) {
			$server->addToolkit($toolkit);
		}

		// Custom user toolkits
		foreach ($config['toolkits'] ?? [] as $class) {
			$server->addToolkit(new $class($bridge));
		}

		return $server;
	}
}
