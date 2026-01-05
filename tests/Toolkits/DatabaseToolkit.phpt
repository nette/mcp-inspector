<?php

declare(strict_types=1);

use Nette\Database\Explorer;
use Nette\Database\IStructure;
use Nette\DI\Container;
use Nette\McpInspector\Bridge\BootstrapBridge;
use Nette\McpInspector\Toolkits\DatabaseToolkit;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('tryCreate returns null when Explorer not configured', function () {
	$container = Mockery::mock(Container::class);
	$container->shouldReceive('hasService')->with('database.default.explorer')->andReturn(false);
	$container->shouldReceive('getByType')->with(Explorer::class)->andThrow(new Exception('Not found'));

	$bridge = Mockery::mock(BootstrapBridge::class);
	$bridge->shouldReceive('getContainer')->andReturn($container);

	$toolkit = DatabaseToolkit::tryCreate($bridge);

	Assert::null($toolkit);
});


test('getTables returns list of tables', function () {
	$structure = Mockery::mock(IStructure::class);
	$structure->shouldReceive('getTables')->andReturn([
		['name' => 'users', 'view' => false],
		['name' => 'articles', 'view' => false],
		['name' => 'user_stats', 'view' => true],
	]);

	$explorer = Mockery::mock(Explorer::class);
	$explorer->shouldReceive('getStructure')->andReturn($structure);

	$toolkit = new DatabaseToolkit($explorer);
	$result = $toolkit->getTables();

	Assert::same(3, $result['count']);
	Assert::count(3, $result['tables']);
	Assert::same('users', $result['tables'][0]['name']);
	Assert::false($result['tables'][0]['view']);
	Assert::true($result['tables'][2]['view']);
});


test('getColumns returns column info with foreign keys', function () {
	$structure = Mockery::mock(IStructure::class);
	$structure->shouldReceive('getColumns')->with('articles')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'nullable' => false, 'default' => null, 'autoincrement' => true],
		['name' => 'user_id', 'nativetype' => 'int', 'nullable' => false, 'default' => null, 'autoincrement' => false],
		['name' => 'title', 'nativetype' => 'varchar', 'nullable' => false, 'default' => null, 'autoincrement' => false],
	]);
	$structure->shouldReceive('getPrimaryKey')->with('articles')->andReturn('id');
	$structure->shouldReceive('getBelongsToReference')->with('articles')->andReturn([
		'user_id' => ['users', 'id'],
	]);

	$explorer = Mockery::mock(Explorer::class);
	$explorer->shouldReceive('getStructure')->andReturn($structure);

	$toolkit = new DatabaseToolkit($explorer);
	$result = $toolkit->getColumns('articles');

	Assert::same('articles', $result['table']);
	Assert::same('id', $result['primaryKey']);
	Assert::count(3, $result['columns']);

	// Check id column
	Assert::same('id', $result['columns'][0]['name']);
	Assert::true($result['columns'][0]['primary']);
	Assert::true($result['columns'][0]['autoincrement']);

	// Check user_id column with foreign key
	Assert::same('user_id', $result['columns'][1]['name']);
	Assert::same(['table' => 'users', 'column' => 'id'], $result['columns'][1]['foreignKey']);
});


test('getColumns returns error for non-existent table', function () {
	$structure = Mockery::mock(IStructure::class);
	$structure->shouldReceive('getColumns')->with('nonexistent')->andThrow(new Exception('Table not found'));

	$explorer = Mockery::mock(Explorer::class);
	$explorer->shouldReceive('getStructure')->andReturn($structure);

	$toolkit = new DatabaseToolkit($explorer);
	$result = $toolkit->getColumns('nonexistent');

	Assert::contains("Table 'nonexistent' not found", $result['error']);
});


test('getRelationships returns all relationships', function () {
	$structure = Mockery::mock(IStructure::class);
	$structure->shouldReceive('getTables')->andReturn([
		['name' => 'users'],
		['name' => 'articles'],
	]);
	$structure->shouldReceive('getBelongsToReference')->with('users')->andReturn(null);
	$structure->shouldReceive('getBelongsToReference')->with('articles')->andReturn([
		'user_id' => ['users', 'id'],
	]);
	$structure->shouldReceive('getHasManyReference')->with('users')->andReturn([
		'articles' => ['user_id'],
	]);
	$structure->shouldReceive('getHasManyReference')->with('articles')->andReturn(null);

	$explorer = Mockery::mock(Explorer::class);
	$explorer->shouldReceive('getStructure')->andReturn($structure);

	$toolkit = new DatabaseToolkit($explorer);
	$result = $toolkit->getRelationships();

	Assert::same(2, $result['count']);

	// belongsTo relationship
	$belongsTo = array_filter($result['relationships'], fn($r) => $r['type'] === 'belongsTo');
	Assert::count(1, $belongsTo);

	// hasMany relationship
	$hasMany = array_filter($result['relationships'], fn($r) => $r['type'] === 'hasMany');
	Assert::count(1, $hasMany);
});


test('suggestEntity returns not implemented', function () {
	$explorer = Mockery::mock(Explorer::class);

	$toolkit = new DatabaseToolkit($explorer);
	$result = $toolkit->suggestEntity('users');

	Assert::same('Not yet implemented', $result['error']);
});
