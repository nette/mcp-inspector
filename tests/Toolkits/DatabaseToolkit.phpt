<?php declare(strict_types=1);

use Nette\Database\Connection;
use Nette\Database\Driver;
use Nette\Database\Explorer;
use Nette\Database\Reflection;
use Nette\DI\Container;
use Nette\McpInspector\Toolkits\DatabaseToolkit;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** Builds an accessor whose container resolves Explorer::class to an Explorer wrapping the given Driver. */
function accessorWithDriver(Driver $driver): Nette\McpInspector\ContainerAccessor
{
	$reflection = new Reflection($driver);

	$connection = Mockery::mock(Connection::class);
	$connection->shouldReceive('getReflection')->andReturn($reflection);

	$explorer = Mockery::mock(Explorer::class);
	$explorer->shouldReceive('getConnection')->andReturn($connection);

	$container = Mockery::mock(Container::class);
	$container->shouldReceive('getByType')->with(Explorer::class)->andReturn($explorer);
	return wrapAccessor($container);
}


test('tryCreate returns null when Explorer not configured', function () {
	$container = Mockery::mock(Container::class);
	$container->shouldReceive('getByType')->with(Explorer::class)->andThrow(new Exception('Not found'));

	$toolkit = DatabaseToolkit::tryCreate(wrapAccessor($container));

	Assert::null($toolkit);
});


test('getTables returns list of tables', function () {
	$driver = Mockery::mock(Driver::class);
	$driver->shouldReceive('getTables')->andReturn([
		['name' => 'users', 'view' => false],
		['name' => 'articles', 'view' => false],
		['name' => 'user_stats', 'view' => true],
	]);

	$toolkit = new DatabaseToolkit(accessorWithDriver($driver));
	$result = $toolkit->getTables();

	Assert::same(3, $result['count']);
	Assert::count(3, $result['tables']);
	Assert::same('users', $result['tables'][0]['name']);
	Assert::false($result['tables'][0]['view']);
	Assert::true($result['tables'][2]['view']);
});


test('getColumns returns column info with foreign keys', function () {
	$driver = Mockery::mock(Driver::class);
	$driver->shouldReceive('getTables')->andReturn([
		['name' => 'users', 'view' => false],
		['name' => 'articles', 'view' => false],
	]);
	$driver->shouldReceive('getColumns')->with('articles')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => true, 'primary' => true, 'vendor' => []],
		['name' => 'user_id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => false, 'primary' => false, 'vendor' => []],
		['name' => 'title', 'nativetype' => 'varchar', 'size' => 255, 'nullable' => false, 'default' => null, 'autoincrement' => false, 'primary' => false, 'vendor' => []],
	]);
	$driver->shouldReceive('getColumns')->with('users')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => true, 'primary' => true, 'vendor' => []],
	]);
	$driver->shouldReceive('getForeignKeys')->with('articles')->andReturn([
		['name' => 'fk_user', 'local' => 'user_id', 'table' => 'users', 'foreign' => 'id'],
	]);

	$toolkit = new DatabaseToolkit(accessorWithDriver($driver));
	$result = $toolkit->getColumns('articles');

	Assert::same('articles', $result['table']);
	Assert::same(['id'], $result['primaryKey']);
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
	$driver = Mockery::mock(Driver::class);
	$driver->shouldReceive('getTables')->andReturn([]);

	$toolkit = new DatabaseToolkit(accessorWithDriver($driver));
	$result = $toolkit->getColumns('nonexistent');

	Assert::contains("Table 'nonexistent' not found", $result['error']);
});


test('getRelationships emits belongsTo and inverse hasMany per FK', function () {
	$driver = Mockery::mock(Driver::class);
	$driver->shouldReceive('getTables')->andReturn([
		['name' => 'users', 'view' => false],
		['name' => 'articles', 'view' => false],
	]);
	$driver->shouldReceive('getColumns')->with('users')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => true, 'primary' => true, 'vendor' => []],
	]);
	$driver->shouldReceive('getColumns')->with('articles')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => true, 'primary' => true, 'vendor' => []],
		['name' => 'user_id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => false, 'primary' => false, 'vendor' => []],
	]);
	$driver->shouldReceive('getForeignKeys')->with('users')->andReturn([]);
	$driver->shouldReceive('getForeignKeys')->with('articles')->andReturn([
		['name' => 'fk_user', 'local' => 'user_id', 'table' => 'users', 'foreign' => 'id'],
	]);

	$toolkit = new DatabaseToolkit(accessorWithDriver($driver));
	$result = $toolkit->getRelationships();

	// One FK → one belongsTo + one hasMany
	Assert::same(2, $result['count']);

	$belongsTo = array_values(array_filter($result['relationships'], fn($r) => $r['type'] === 'belongsTo'));
	Assert::count(1, $belongsTo);
	Assert::same('articles', $belongsTo[0]['from']['table']);
	Assert::same('users', $belongsTo[0]['to']['table']);

	$hasMany = array_values(array_filter($result['relationships'], fn($r) => $r['type'] === 'hasMany'));
	Assert::count(1, $hasMany);
	Assert::same('users', $hasMany[0]['from']['table']);
	Assert::same('articles', $hasMany[0]['to']['table']);
});


test('getIndexes returns indexes via Reflection (driver-agnostic)', function () {
	$driver = Mockery::mock(Driver::class);
	$driver->shouldReceive('getTables')->andReturn([
		['name' => 'users', 'view' => false],
	]);
	$driver->shouldReceive('getColumns')->with('users')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => true, 'primary' => true, 'vendor' => []],
		['name' => 'email', 'nativetype' => 'varchar', 'size' => 255, 'nullable' => false, 'default' => null, 'autoincrement' => false, 'primary' => false, 'vendor' => []],
	]);
	$driver->shouldReceive('getIndexes')->with('users')->andReturn([
		['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true],
		['name' => 'idx_email', 'columns' => ['email'], 'unique' => true, 'primary' => false],
	]);

	$toolkit = new DatabaseToolkit(accessorWithDriver($driver));
	$result = $toolkit->getIndexes('users');

	Assert::same('users', $result['table']);
	Assert::same(2, $result['count']);
	Assert::same('PRIMARY', $result['indexes'][0]['name']);
	Assert::true($result['indexes'][0]['primary']);
	Assert::same(['email'], $result['indexes'][1]['columns']);
});


test('generateEntity returns PHP entity code', function () {
	$driver = Mockery::mock(Driver::class);
	$driver->shouldReceive('getTables')->andReturn([
		['name' => 'users', 'view' => false],
	]);
	$driver->shouldReceive('getColumns')->with('users')->andReturn([
		['name' => 'id', 'nativetype' => 'int', 'size' => null, 'nullable' => false, 'default' => null, 'autoincrement' => true, 'primary' => true, 'vendor' => []],
		['name' => 'email', 'nativetype' => 'varchar', 'size' => 255, 'nullable' => false, 'default' => null, 'autoincrement' => false, 'primary' => false, 'vendor' => []],
		['name' => 'name', 'nativetype' => 'varchar', 'size' => 255, 'nullable' => true, 'default' => null, 'autoincrement' => false, 'primary' => false, 'vendor' => []],
	]);

	$toolkit = new DatabaseToolkit(accessorWithDriver($driver));
	$result = $toolkit->generateEntity('users');

	Assert::same('users', $result['table']);
	Assert::same('User', $result['className']);
	Assert::contains('@property int $id', $result['code']);
	Assert::contains('@property string $email', $result['code']);
	Assert::contains('@property ?string $name', $result['code']);
	Assert::contains('class User extends', $result['code']);
});
