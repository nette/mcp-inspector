<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Mcp\Schema\ToolAnnotations;


/**
 * A toolkit whose tool annotations depend on its configuration (e.g. a query tool that is read-only
 * unless configured otherwise) adjusts the annotations declared in #[McpTool] at registration time.
 */
interface ToolAnnotator
{
	public function annotateTool(string $name, ToolAnnotations $annotations): ToolAnnotations;
}
