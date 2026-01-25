<?php declare(strict_types=1);

namespace Nette\McpInspector;


/**
 * A group of MCP tools. Public methods marked with #[McpTool] become tools of the server.
 */
interface Toolkit
{
	/**
	 * Whether the inspected application provides what the toolkit needs (e.g. a database connection).
	 * Unavailable toolkits are left out of the server without an error.
	 */
	function isAvailable(): bool;
}
