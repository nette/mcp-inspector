<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Nette\DI\Container;


/**
 * Abstraction over a source of the project's DI container.
 * Toolkits depend on this interface so alternative sources (mock, HTTP-served, …)
 * can be plugged in. Expected to be cheap to call on every tool invocation.
 */
interface ContainerAccessor
{
	/**
	 * Returns the current DI container. May be rebuilt per call (to pick up config
	 * changes) or returned as a stale fallback if a fresh build failed — in that
	 * case {@see decorateResult()} attaches a warning to outgoing tool results.
	 */
	public function getContainer(): Container;

	/**
	 * Adds bookkeeping (e.g. stale-container warnings) to a tool's result array.
	 * Toolkits should pipe their successful results through this.
	 * @param  array<string, mixed>  $result
	 * @return array<string, mixed>
	 */
	public function decorateResult(array $result): array;
}
