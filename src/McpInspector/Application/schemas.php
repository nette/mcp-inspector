<?php declare(strict_types=1);

namespace Nette\McpInspector\Application\Schemas;


/**
 * Result of app_get_info.
 */
final class AppInfo
{
	/**
	 * @param  string  $php  PHP version
	 * @param  ?string  $nette  version of nette/application (or nette/di), null when unknown
	 * @param  array<string, string>  $packages  Nette family packages from composer.lock, name => version
	 * @param  array<string, string>  $directories  rootDir, appDir, wwwDir, tempDir, vendorDir, logDir when known
	 * @param  ?string  $database  PDO driver of the application's database connection (mysql, pgsql, sqlite…), null without a database
	 */
	public function __construct(
		public readonly string $php,
		public readonly ?string $nette,
		public readonly array $packages,
		public readonly array $directories,
		public readonly ?string $database,
	) {
	}
}
