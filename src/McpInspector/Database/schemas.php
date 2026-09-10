<?php declare(strict_types=1);

namespace Nette\McpInspector\Database\Schemas;


final class Table
{
	public function __construct(
		public readonly string $name,
		public readonly bool $view,
	) {
	}
}


/**
 * Result of db_get_tables.
 */
final class TableList
{
	public function __construct(
		/** @var list<Table> */
		public readonly array $tables,
		public readonly int $count,
	) {
	}
}


/**
 * A column of a table, as one end of a foreign key.
 */
final class ColumnRef
{
	public function __construct(
		public readonly string $table,
		public readonly ?string $column,
	) {
	}
}


final class Column
{
	public function __construct(
		public readonly string $name,
		/** native database type */
		public readonly string $type,
		public readonly bool $nullable,
		public readonly mixed $default,
		public readonly bool $primary,
		public readonly bool $autoincrement,
		/** the column this one references, if it is a foreign key */
		public readonly ?ColumnRef $foreignKey,
	) {
	}
}


/**
 * Result of db_get_columns.
 */
final class ColumnList
{
	public function __construct(
		public readonly string $table,
		/** @var list<Column> */
		public readonly array $columns,
		/** @var ?list<string> columns of the primary key, null when the table has none */
		public readonly ?array $primaryKey,
	) {
	}
}


final class Relationship
{
	public function __construct(
		/** belongsTo (from holds the foreign key) or hasMany (its inverse) */
		public readonly string $type,
		public readonly ColumnRef $from,
		public readonly ColumnRef $to,
	) {
	}
}


/**
 * Result of db_get_relationships.
 */
final class RelationshipList
{
	public function __construct(
		/** @var list<Relationship> */
		public readonly array $relationships,
		public readonly int $count,
	) {
	}
}


final class Index
{
	public function __construct(
		public readonly string $name,
		public readonly bool $unique,
		public readonly bool $primary,
		/** @var list<string> */
		public readonly array $columns,
	) {
	}
}


/**
 * Result of db_get_indexes.
 */
final class IndexList
{
	public function __construct(
		public readonly string $table,
		/** @var list<Index> */
		public readonly array $indexes,
		public readonly int $count,
	) {
	}
}


/**
 * Result of db_query: rows of a SELECT-like statement, or the number of affected rows otherwise.
 */
final class QueryResult
{
	public function __construct(
		/** @var list<array<string, mixed>> column => value; secrets replaced by *** */
		public readonly array $rows,
		/** number of rows returned */
		public readonly int $count,
		/** for statements without a result set */
		public readonly ?int $affectedRows,
		/** present when the result was cut at the row limit */
		public readonly ?string $truncated,
	) {
	}
}


/**
 * Result of db_explain_query.
 */
final class QueryPlan
{
	public function __construct(
		public readonly string $query,
		/** @var list<array<string, mixed>> rows of EXPLAIN as the driver returns them */
		public readonly array $plan,
	) {
	}
}
