<?php

declare(strict_types=1);

namespace Nette\McpInspector\Bridge;

use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerBuilder;


/**
 * Bridge to Nette application for accessing DI container.
 */
class BootstrapBridge
{
	private ?ContainerBuilder $builder = null;
	private ?Container $container = null;


	public function __construct(
		private string $bootstrapPath = 'app/Bootstrap.php',
		private string $bootstrapClass = 'App\Bootstrap',
	) {
	}


	public function getContainerBuilder(): ContainerBuilder
	{
		if ($this->builder === null) {
			$this->compile();
		}

		return $this->builder;
	}


	public function getContainer(): Container
	{
		if ($this->container === null) {
			$this->container = $this->createContainer();
		}

		return $this->container;
	}


	private function compile(): void
	{
		require_once getcwd() . '/' . $this->bootstrapPath;

		$configurator = ($this->bootstrapClass)::boot();
		$configurator->onCompile[] = function ($cfg, Compiler $compiler) {
			$compiler->processExtensions();
			$this->builder = $compiler->getContainerBuilder();
		};
		$configurator->loadContainer();
	}


	private function createContainer(): Container
	{
		require_once getcwd() . '/' . $this->bootstrapPath;
		return ($this->bootstrapClass)::boot()->createContainer();
	}
}
