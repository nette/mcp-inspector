<?php

declare(strict_types=1);

use Nette\DI\ContainerBuilder;
use Nette\McpInspector\Toolkits\DIToolkit;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('getServices returns all services', function () {
	$builder = new ContainerBuilder;
	$builder->addDefinition('foo')
		->setType('stdClass');
	$builder->addDefinition('bar')
		->setType('ArrayObject');

	$toolkit = new DIToolkit($builder);
	$result = $toolkit->getServices();

	// ContainerBuilder adds 'container' service automatically
	Assert::same(3, $result['count']);
	Assert::count(3, $result['services']);

	// Filter out internal 'container' service for assertions
	$userServices = array_values(array_filter(
		$result['services'],
		fn($s) => $s['name'] !== 'container',
	));
	Assert::same('foo', $userServices[0]['name']);
	Assert::same('stdClass', $userServices[0]['type']);
	Assert::same('bar', $userServices[1]['name']);
	Assert::same('ArrayObject', $userServices[1]['type']);
});


test('getServices filters by name', function () {
	$builder = new ContainerBuilder;
	$builder->addDefinition('database.connection')
		->setType('PDO');
	$builder->addDefinition('cache.storage')
		->setType('stdClass');

	$toolkit = new DIToolkit($builder);
	$result = $toolkit->getServices('database');

	Assert::same(1, $result['count']);
	Assert::same('database.connection', $result['services'][0]['name']);
});


test('getServices filters by type', function () {
	$builder = new ContainerBuilder;
	$builder->addDefinition('foo')
		->setType('stdClass');
	$builder->addDefinition('bar')
		->setType('ArrayObject');

	$toolkit = new DIToolkit($builder);
	$result = $toolkit->getServices('Array');

	Assert::same(1, $result['count']);
	Assert::same('bar', $result['services'][0]['name']);
});


test('getService returns service details', function () {
	$builder = new ContainerBuilder;
	$builder->addDefinition('myService')
		->setType('stdClass')
		->addTag('mytag', 'value');

	$toolkit = new DIToolkit($builder);
	$result = $toolkit->getService('myService');

	Assert::same('myService', $result['name']);
	Assert::same('stdClass', $result['type']);
	Assert::same('service', $result['kind']);
	Assert::same(['mytag' => 'value'], $result['tags']);
});


test('getService returns error for non-existent service', function () {
	$builder = new ContainerBuilder;

	$toolkit = new DIToolkit($builder);
	$result = $toolkit->getService('nonexistent');

	Assert::same("Service 'nonexistent' not found", $result['error']);
});
