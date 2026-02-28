<?php declare(strict_types=1);

namespace Nette\McpInspector\Database\Schemas;


final readonly class Table
{
	public function __construct(
		public string $name,
		public bool $view,
	) {
	}
}


/**
 * Result of db_get_tables.
 */
final readonly class TableList
{
	public function __construct(
		/** @var list<Table> */
		public array $tables,
		public int $count,
	) {
	}
}


/**
 * A column of a table, as one end of a foreign key.
 */
final readonly class ColumnRef
{
	public function __construct(
		public string $table,
		public ?string $column,
	) {
	}
}


final readonly class Column
{
	public function __construct(
		public string $name,
		/** native database type */
		public string $type,
		public bool $nullable,
		public mixed $default,
		public bool $primary,
		public bool $autoincrement,
		/** the column this one references, if it is a foreign key */
		public ?ColumnRef $foreignKey,
	) {
	}
}


/**
 * Result of db_get_columns.
 */
final readonly class ColumnList
{
	public function __construct(
		public string $table,
		/** @var list<Column> */
		public array $columns,
		/** @var ?list<string> columns of the primary key, null when the table has none */
		public ?array $primaryKey,
	) {
	}
}


final readonly class Relationship
{
	public function __construct(
		/** belongsTo (from holds the foreign key) or hasMany (its inverse) */
		public string $type,
		public ColumnRef $from,
		public ColumnRef $to,
	) {
	}
}


/**
 * Result of db_get_relationships.
 */
final readonly class RelationshipList
{
	public function __construct(
		/** @var list<Relationship> */
		public array $relationships,
		public int $count,
	) {
	}
}


final readonly class Index
{
	public function __construct(
		public string $name,
		public bool $unique,
		public bool $primary,
		/** @var list<string> */
		public array $columns,
	) {
	}
}


/**
 * Result of db_get_indexes.
 */
final readonly class IndexList
{
	public function __construct(
		public string $table,
		/** @var list<Index> */
		public array $indexes,
		public int $count,
	) {
	}
}


/**
 * Result of db_query: rows of a SELECT-like statement, or the number of affected rows otherwise.
 */
final readonly class QueryResult
{
	public function __construct(
		/** @var list<array<string, mixed>> column => value; secrets replaced by *** */
		public array $rows,
		/** number of rows returned */
		public int $count,
		/** for statements without a result set */
		public ?int $affectedRows,
		/** present when the result was cut at the row limit */
		public ?string $truncated,
	) {
	}
}


/**
 * Result of db_explain_query.
 */
final readonly class QueryPlan
{
	public function __construct(
		public string $query,
		/** @var list<array<string, mixed>> rows of EXPLAIN as the driver returns them */
		public array $plan,
	) {
	}
}
