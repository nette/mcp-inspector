<?php declare(strict_types=1);

namespace Nette\McpInspector;


/**
 * Marker interface for MCP toolkits — used for auto-discovery via
 * `Container::findByType(Toolkit::class)`. Annotate methods with
 * `#[Mcp\Capability\Attribute\McpTool]` to expose them as MCP tools.
 */
interface Toolkit
{
}
