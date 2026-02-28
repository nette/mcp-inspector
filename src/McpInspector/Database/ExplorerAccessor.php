<?php declare(strict_types=1);

namespace Nette\McpInspector\Database;

use Nette\Database\ConnectionLostException;
use Nette\Database\Explorer;
use Nette\McpInspector\AppContainer;


/**
 * The inspected application's database Explorer, resolved on every call so reloads are honoured.
 */
final class ExplorerAccessor
{
	public function __construct(
		private readonly AppContainer $app,
	) {
	}


	public function isAvailable(): bool
	{
		return class_exists(Explorer::class)
			&& $this->app->get()->getByType(Explorer::class, throw: false) !== null;
	}


	/**
	 * Returns the Explorer with a live connection: the MCP process runs for hours and the database server
	 * drops idle connections, so a dead one is reconnected before the tool uses it.
	 */
	public function get(): Explorer
	{
		$explorer = $this->app->get()->getByType(Explorer::class);
		$connection = $explorer->getConnection();
		try {
			$connection->query('SELECT 1');
		} catch (ConnectionLostException) {
			$connection->reconnect();
		}

		return $explorer;
	}
}
