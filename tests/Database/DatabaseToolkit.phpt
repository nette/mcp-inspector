<?php declare(strict_types=1);

/**
 * Test: DatabaseToolkit over a real SQLite database of the inspected application.
 */

use Mcp\Exception\ToolCallException;
use Nette\Database\Explorer;
use Nette\McpInspector\AppContainer;
use Nette\McpInspector\Database\DatabaseToolkit;
use Nette\McpInspector\Database\Schemas\ColumnRef;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Server;
use Nette\Utils\FileSystem;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


$project = createTestProject(<<<'NEON'
	database:
		dsn: 'sqlite:%tempDir%/db.sqlite'
	NEON);

$connection = (new AppContainer($project))->get()->getByType(Explorer::class)->getConnection();
$connection->query('CREATE TABLE author (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, password TEXT)');
$connection->query('CREATE TABLE book (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL REFERENCES author(id), title TEXT, CONSTRAINT title_unique UNIQUE (title))');
$connection->query("INSERT INTO author (name, password) VALUES ('john', 'secret'), ('jane', 'hidden'), ('joe', NULL)");
$connection->query("INSERT INTO book (author_id, title) VALUES (1, 'Nette')");

$container = Inspector::createContainer($project);
$toolkit = $container->getByType(DatabaseToolkit::class);
Assert::true($toolkit->isAvailable());
Assert::contains('db_query', $container->getByType(Server::class)->getToolNames());
assertToolsComplete($container->getByType(Server::class));
foreach ($container->getByType(Server::class)->getTools() as $tool) {
	if ($tool->name === 'db_query') {
		Assert::true($tool->annotations->readOnlyHint); // readOnly: true is the default, the client may auto-approve
	}
}


test('schema tools describe tables, columns, keys and indexes', function () use ($toolkit) {
	Assert::same(['author', 'book'], array_column($toolkit->getTables()->tables, 'name'));

	$columns = $toolkit->getColumns('book');
	Assert::same(['id'], $columns->primaryKey);
	Assert::same('author_id', $columns->columns[1]->name);
	Assert::equal(new ColumnRef('author', 'id'), $columns->columns[1]->foreignKey);
	Assert::false($columns->columns[1]->nullable);

	$relationships = $toolkit->getRelationships()->relationships;
	Assert::same('belongsTo', $relationships[0]->type);
	Assert::equal(new ColumnRef('book', 'author_id'), $relationships[0]->from);
	Assert::same('hasMany', $relationships[1]->type);

	$indexes = $toolkit->getIndexes('book')->indexes;
	Assert::same(['title'], $indexes[0]->columns);
	Assert::true($indexes[0]->unique);

	Assert::exception(fn() => $toolkit->getColumns('missing'), ToolCallException::class, "Table 'missing' not found");
});


test('SELECT returns rows and masks secret-looking columns', function () use ($toolkit) {
	$result = $toolkit->query('SELECT id, name, password FROM author ORDER BY id;');
	Assert::same([
		['id' => 1, 'name' => 'john', 'password' => '***'],
		['id' => 2, 'name' => 'jane', 'password' => '***'],
		['id' => 3, 'name' => 'joe', 'password' => null],
	], $result->rows);
	Assert::same(3, $result->count);
	Assert::null($result->truncated);
	Assert::null($result->affectedRows);

	Assert::same([['id' => 1, 'author_id' => 1, 'title' => 'Nette']], $toolkit->query('SELECT id, author_id, title FROM book')->rows); // author_id is not a secret
});


test('values are bound to ? placeholders with their types', function () use ($toolkit) {
	Assert::same([['name' => 'jane']], $toolkit->query('SELECT name FROM author WHERE id = ? AND name <> ?', [2, 'x'])->rows);
	Assert::same([['x' => 1]], $toolkit->query('SELECT 1 AS x WHERE 1 = ?', [1])->rows); // an integer, not the string '1'
	Assert::same([], $toolkit->query('SELECT 1 AS x WHERE 1 = ?', ['1'])->rows);
});


test('through the server, rows are marked as untrusted data', function () use ($container) {
	$result = callTool($container->getByType(Server::class), 'db_query', ['query' => 'SELECT id FROM author WHERE id = ?', 'params' => [1]]);
	Assert::same([['id' => 1]], $result['rows']);
	Assert::contains('untrusted', $result['_notice']);
	Assert::false(isset($result['_warning']));
});


test('read access rejects modifications before they reach the database', function () use ($toolkit, $connection) {
	Assert::exception(fn() => $toolkit->query('DELETE FROM author'), ToolCallException::class, 'Only SELECT-like statements are allowed;%a%');
	Assert::exception(fn() => $toolkit->query('SELECT 1; DELETE FROM author'), ToolCallException::class, 'Multi-statement queries are not allowed');
	Assert::same(3, $connection->fetchField('SELECT COUNT(*) FROM author'));
});


test('read access is enforced by the database as well', function () use ($toolkit, $connection) {
	Assert::exception(fn() => $toolkit->query('WITH x AS (SELECT 1) DELETE FROM author'), Throwable::class); // passes the validator, rejected by SQLite
	Assert::same(3, $connection->fetchField('SELECT COUNT(*) FROM author'));
	Assert::same(0, $connection->fetchField('PRAGMA query_only')); // the connection is writable again
});


test('EXPLAIN accepts SELECT only', function () use ($toolkit) {
	Assert::type('array', $toolkit->explainQuery('EXPLAIN SELECT * FROM author')->plan);
	Assert::exception(fn() => $toolkit->explainQuery('DELETE FROM author'), ToolCallException::class, 'Only SELECT queries can be explained');
});


test('readOnly: false and the row limit come from the configuration', function () use ($project, $connection) {
	FileSystem::write("$project/" . Inspector::ConfigFile, "database:\n\treadOnly: false\n\trowLimit: 2\n");
	touch("$project/" . Inspector::ConfigFile, time() + 10);
	$container = Inspector::createContainer($project);
	$toolkit = $container->getByType(DatabaseToolkit::class);
	foreach ($container->getByType(Server::class)->getTools() as $tool) {
		if ($tool->name === 'db_query') {
			Assert::false($tool->annotations->readOnlyHint);
			Assert::true($tool->annotations->destructiveHint);
		}
	}

	$result = $toolkit->query('SELECT id FROM author ORDER BY id');
	Assert::same(2, $result->count);
	Assert::same('only the first 2 rows are shown', $result->truncated);

	Assert::same(2, $toolkit->query("UPDATE author SET name = 'x' WHERE id > 1")->affectedRows);
	Assert::same(2, $connection->fetchField("SELECT COUNT(*) FROM author WHERE name = 'x'"));
});
