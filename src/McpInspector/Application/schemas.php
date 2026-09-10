<?php declare(strict_types=1);

namespace Nette\McpInspector\Application\Schemas;


/**
 * Result of app_get_info.
 */
final class AppInfo
{
	public function __construct(
		/** PHP version */
		public readonly string $php,
		/** version of nette/application (or nette/di), null when unknown */
		public readonly ?string $nette,
		/** @var array<string, string> Nette family packages from composer.lock, name => version */
		public readonly array $packages,
		/** @var array<string, string> rootDir, appDir, wwwDir, tempDir, vendorDir, logDir when known */
		public readonly array $directories,
		/** PDO driver of the application's database connection (mysql, pgsql, sqlite…), null without a database */
		public readonly ?string $database,
	) {
	}
}
