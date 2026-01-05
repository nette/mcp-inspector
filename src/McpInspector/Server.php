<?php

declare(strict_types=1);

namespace Nette\McpInspector;

use Mcp\Server as McpServer;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;


/**
 * MCP server orchestrator with dual transport support.
 */
class Server
{
	/** @var Toolkit[] */
	private array $toolkits = [];


	public function addToolkit(Toolkit $toolkit): self
	{
		$this->toolkits[] = $toolkit;
		return $this;
	}


	/**
	 * Run with stdio transport (CLI).
	 */
	public function runCli(): void
	{
		$this->build()->run(new StdioTransport);
	}


	/**
	 * Run with HTTP transport, returns PSR-7 Response.
	 */
	public function runHttp(
		ServerRequestInterface $request,
		ResponseFactoryInterface $responseFactory,
		StreamFactoryInterface $streamFactory,
	): ResponseInterface
	{
		$transport = new StreamableHttpTransport($request, $responseFactory, $streamFactory);
		return $this->build()->run($transport);
	}


	private function build(): McpServer
	{
		$builder = McpServer::builder()
			->setServerInfo('Nette MCP Inspector', '1.0.0');

		// Register toolkit instances - SDK discovers #[McpTool] methods via reflection
		foreach ($this->toolkits as $toolkit) {
			$builder->addToolsFromObject($toolkit);
		}

		return $builder->build();
	}
}
