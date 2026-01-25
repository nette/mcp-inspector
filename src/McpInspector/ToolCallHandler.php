<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\Content;
use Nette\Utils\AssertionException;
use Nette\Utils\Json;
use function is_array, is_object, sprintf;


/**
 * Runs every tool call: converts anything a tool throws into a ToolCallException (rendered as an error result
 * the model can react to, instead of an opaque JSON-RPC error), appends `_notice` to results of #[UntrustedData] tools,
 * and `_warning` when the last rebuild of the application container failed, so the model knows the data is stale.
 */
final class ToolCallHandler implements ReferenceHandlerInterface
{
	public function __construct(
		private readonly ReferenceHandlerInterface $handler,
		private readonly AppContainer $app,
	) {
	}


	/**
	 * @param  array<string, mixed>  $arguments
	 */
	public function handle(ElementReference $reference, array $arguments): mixed
	{
		try {
			$result = $this->handler->handle($reference, $arguments);
		} catch (\Throwable $e) {
			throw match (true) {
				$e instanceof ToolCallException => $e,
				// clean caller-input message
				$e instanceof \InvalidArgumentException, $e instanceof AssertionException, $e instanceof RegistryException => new ToolCallException($e->getMessage(), 0, $e),
				default => new ToolCallException(sprintf('%s (%s in %s:%d)', $e->getMessage(), $e::class, basename($e->getFile()), $e->getLine()), 0, $e),
			};
		}

		if (
			is_object($result)
			&& !$result instanceof Content
		) { // a result object becomes the JSON the client sees, so the fields below can be appended
			$result = Json::decode(Json::encode($result), forceArrays: true);
		}
		if (!is_array($result)) {
			return $result;
		}

		if (
			$reference->handler instanceof \Closure
			&& (new \ReflectionFunction($reference->handler))->getAttributes(UntrustedData::class)
		) {
			$result['_notice'] = UntrustedData::Notice;
		}

		$error = $this->app->getLastError();
		if ($error !== null) {
			$result['_warning'] = sprintf(
				'Container rebuild failed (%s: %s). Showing data from the last successful build. Fix and re-run to refresh.',
				$error::class,
				$error->getMessage(),
			);
		}

		return $result;
	}
}
