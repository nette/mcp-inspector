<?php declare(strict_types=1);

namespace Nette\McpInspector\Database;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Nette\Database\Reflection;
use Nette\McpInspector\Database\Schemas\Column;
use Nette\McpInspector\Database\Schemas\ColumnList;
use Nette\McpInspector\Database\Schemas\ColumnRef;
use Nette\McpInspector\Database\Schemas\Index;
use Nette\McpInspector\Database\Schemas\IndexList;
use Nette\McpInspector\Database\Schemas\QueryPlan;
use Nette\McpInspector\Database\Schemas\QueryResult;
use Nette\McpInspector\Database\Schemas\Relationship;
use Nette\McpInspector\Database\Schemas\RelationshipList;
use Nette\McpInspector\Database\Schemas\Table;
use Nette\McpInspector\Database\Schemas\TableList;
use Nette\McpInspector\Masking;
use Nette\McpInspector\ToolAnnotator;
use Nette\McpInspector\Toolkit;
use Nette\McpInspector\UntrustedData;
use function count, is_bool, is_int;


/**
 * Database schema introspection and queries. Schema tools go through the driver-agnostic
 * Nette\Database\Reflection; db_query and db_explain_query issue raw SQL under the `database: readOnly:` policy.
 */
class DatabaseToolkit implements Toolkit, ToolAnnotator
{
	public function __construct(
		private readonly ExplorerAccessor $explorer,
		private readonly Masking $masking,
		private readonly bool $readOnly = true,
		private readonly int $rowLimit = 100,
	) {
	}


	public function isAvailable(): bool
	{
		return $this->explorer->isAvailable();
	}


	public function annotateTool(string $name, ToolAnnotations $annotations): ToolAnnotations
	{
		return $name === 'db_query' && $this->readOnly
			? new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false)
			: $annotations;
	}


	/**
	 * List all database tables and views.
	 */
	#[McpTool(
		name: 'db_get_tables',
		title: 'Database tables',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getTables(): TableList
	{
		$tables = [];
		foreach ($this->reflection()->getTables() as $table) {
			$tables[] = new Table($table->name, $table->view);
		}

		return new TableList($tables, count($tables));
	}


	/**
	 * Get columns of a table with types, nullability, defaults, primary key and foreign keys.
	 * @param string  $table The exact table name as it exists in the database
	 */
	#[McpTool(
		name: 'db_get_columns',
		title: 'Table columns',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getColumns(
		#[Schema(minLength: 1)]
		string $table,
	): ColumnList
	{
		$tableInfo = $this->getTableInfo($table);

		$foreignKeys = [];
		foreach ($tableInfo->foreignKeys as $fk) {
			foreach ($fk->localColumns as $i => $local) {
				$foreignKeys[$local->name] = new ColumnRef($fk->foreignTable->name, $fk->foreignColumns[$i]->name ?? null);
			}
		}

		$columns = [];
		foreach ($tableInfo->columns as $column) {
			$columns[] = new Column(
				name: $column->name,
				type: $column->nativeType,
				nullable: $column->nullable,
				default: $column->default,
				primary: $column->primary,
				autoincrement: $column->autoIncrement,
				foreignKey: $foreignKeys[$column->name] ?? null,
			);
		}

		return new ColumnList(
			table: $table,
			columns: $columns,
			primaryKey: $tableInfo->primaryKey === null
				? null
				: array_map(fn(Reflection\Column $c) => $c->name, $tableInfo->primaryKey->columns),
		);
	}


	/**
	 * Get all foreign key relationships between tables (belongsTo, and hasMany as its inverse).
	 */
	#[McpTool(
		name: 'db_get_relationships',
		title: 'Table relationships',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getRelationships(): RelationshipList
	{
		$relationships = [];
		foreach ($this->reflection()->getTables() as $table) {
			foreach ($table->foreignKeys as $fk) {
				foreach ($fk->localColumns as $i => $local) {
					$from = new ColumnRef($table->name, $local->name);
					$to = new ColumnRef($fk->foreignTable->name, $fk->foreignColumns[$i]->name ?? null);
					$relationships[] = new Relationship('belongsTo', $from, $to);
					$relationships[] = new Relationship('hasMany', $to, $from);
				}
			}
		}

		return new RelationshipList($relationships, count($relationships));
	}


	/**
	 * Get indexes of a table.
	 * @param string  $table The exact table name
	 */
	#[McpTool(
		name: 'db_get_indexes',
		title: 'Table indexes',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getIndexes(
		#[Schema(minLength: 1)]
		string $table,
	): IndexList
	{
		$indexes = [];
		foreach ($this->getTableInfo($table)->indexes as $index) {
			$indexes[] = new Index(
				name: $index->name ?? '',
				unique: $index->unique,
				primary: $index->primary,
				columns: array_map(fn(Reflection\Column $c) => $c->name, $index->columns),
			);
		}

		return new IndexList($table, $indexes, count($indexes));
	}


	/**
	 * Run a single SQL statement and return its rows (SELECT-like) or the number of affected rows.
	 * With `database: readOnly: true` (the default) only SELECT, SHOW, EXPLAIN, DESCRIBE, WITH, VALUES and TABLE
	 * statements are accepted and run inside a read-only transaction; `readOnly: false` allows any statement.
	 * Values of columns whose name suggests a secret are masked. Pass values as `params` bound to `?`
	 * placeholders rather than inlining them into the SQL.
	 * @param string  $query The SQL statement (a single statement) with optional `?` placeholders
	 * @param ?list<scalar|null>  $params Values for the placeholders, in order
	 */
	#[UntrustedData]
	#[McpTool(
		name: 'db_query',
		title: 'SQL query',
		annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
	)]
	public function query(
		#[Schema(minLength: 1)]
		string $query,
		#[Schema(definition: ['type' => ['array', 'null'], 'items' => ['type' => ['string', 'number', 'boolean', 'null']]])] // the SDK does not read list<> docblocks
		?array $params = null,
	): QueryResult
	{
		$query = $this->singleStatement($query);
		$readOnly = $this->readOnly;
		if ($readOnly && !self::isReadStatement($query)) {
			throw new ToolCallException('Only SELECT-like statements are allowed; set `database: readOnly: false` in the inspector configuration to allow modifications');
		}

		$pdo = $this->explorer->get()->getConnection()->getPdo();
		$run = function () use ($pdo, $query, $params): QueryResult {
			$stmt = $pdo->prepare($query);
			if ($stmt === false) {
				throw new ToolCallException('Query failed');
			}
			foreach ($params ?? [] as $i => $value) { // typed binding, execute() would send everything as strings
				$stmt->bindValue($i + 1, $value, match (true) {
					$value === null => \PDO::PARAM_NULL,
					is_int($value) => \PDO::PARAM_INT,
					is_bool($value) => \PDO::PARAM_BOOL,
					default => \PDO::PARAM_STR,
				});
			}
			if (!$stmt->execute()) {
				throw new ToolCallException('Query failed');
			}
			if ($stmt->columnCount() === 0) {
				return new QueryResult(rows: [], count: 0, affectedRows: $stmt->rowCount(), truncated: null);
			}

			$rows = [];
			$truncated = false;
			while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
				if (count($rows) === $this->rowLimit) {
					$truncated = true;
					break;
				}
				foreach ($row as $column => $value) {
					if ($value !== null && $this->masking->shouldMask((string) $column)) {
						$row[$column] = Masking::Placeholder;
					}
				}
				$rows[] = $row;
			}
			$stmt->closeCursor();

			return new QueryResult(
				rows: $rows,
				count: count($rows),
				affectedRows: null,
				truncated: $truncated ? "only the first $this->rowLimit rows are shown" : null,
			);
		};

		return $readOnly ? self::runReadOnly($pdo, $run) : $run();
	}


	/**
	 * Run EXPLAIN on a single SELECT query to analyze its execution plan.
	 * @param string  $query The SELECT query (without the EXPLAIN prefix)
	 */
	#[McpTool(
		name: 'db_explain_query',
		title: 'Explain query',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function explainQuery(
		#[Schema(minLength: 1)]
		string $query,
	): QueryPlan
	{
		$query = preg_replace('~^EXPLAIN\s+~i', '', $this->singleStatement($query));
		if (!preg_match('~^SELECT\b~i', $query)) {
			throw new ToolCallException('Only SELECT queries can be explained');
		}

		$pdo = $this->explorer->get()->getConnection()->getPdo();
		return self::runReadOnly($pdo, function () use ($pdo, $query): QueryPlan {
			$stmt = $pdo->query('EXPLAIN ' . $query);
			return new QueryPlan($query, $stmt === false ? [] : array_values($stmt->fetchAll(\PDO::FETCH_ASSOC)));
		});
	}


	private function singleStatement(string $query): string
	{
		$query = rtrim(trim($query), ';');
		if (str_contains($query, ';')) {
			throw new ToolCallException('Multi-statement queries are not allowed');
		}

		return $query;
	}


	private static function isReadStatement(string $query): bool
	{
		return preg_match('~^[(\s]*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|WITH|VALUES|TABLE)\b~i', $query) === 1
			&& !preg_match('~/\*!|\bINTO\s+(OUTFILE|DUMPFILE)\b~i', $query);
	}


	/**
	 * Runs the callback inside a read-only transaction where the driver supports it, so the database
	 * itself rejects modifications the statement validator did not catch. The transaction is always rolled back.
	 * @template T
	 * @param  \Closure(): T  $fn
	 * @return T
	 */
	private static function runReadOnly(\PDO $pdo, \Closure $fn): mixed
	{
		[$begin, $end] = match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
			'mysql', 'pgsql' => $pdo->inTransaction()
				? [null, null] // START TRANSACTION would commit the application's transaction
				: ['START TRANSACTION READ ONLY', 'ROLLBACK'],
			'sqlite' => ['PRAGMA query_only = 1', 'PRAGMA query_only = ' . (int) ($pdo->query('PRAGMA query_only') ?: null)?->fetchColumn()],
			default => [null, null],
		};

		if ($begin !== null) {
			$pdo->exec($begin);
		}
		try {
			return $fn();
		} finally {
			if ($end !== null) {
				$pdo->exec($end);
			}
		}
	}


	private function reflection(): Reflection
	{
		return $this->explorer->get()->getConnection()->getReflection();
	}


	private function getTableInfo(string $table): Reflection\Table
	{
		$reflection = $this->reflection();
		if (!$reflection->hasTable($table)) {
			throw new ToolCallException("Table '$table' not found");
		}

		return $reflection->getTable($table);
	}
}
