<?php declare(strict_types=1);

namespace Nette\McpInspector\Tracy\Schemas;


/**
 * One line of a Tracy log.
 */
final class LogEntry
{
	public function __construct(
		/** null when the line has no Tracy timestamp */
		public readonly ?string $time,
		public readonly string $message,
		/** URL or CLI command that produced the entry */
		public readonly ?string $source,
		/** name of the markdown exception report for tracy_get_report, null when there is none */
		public readonly ?string $report,
	) {
	}
}


/**
 * Result of tracy_get_log.
 */
final class Log
{
	public function __construct(
		public readonly string $level,
		/** @var list<LogEntry> newest first */
		public readonly array $entries,
		public readonly int $count,
		/** number of lines in the whole log file */
		public readonly int $total,
	) {
	}
}


/**
 * Result of tracy_get_report.
 */
final class Report
{
	public function __construct(
		public readonly string $report,
		/** the markdown report, secrets replaced by *** */
		public readonly string $content,
		/** true when the content was cut at maxLength */
		public readonly bool $truncated,
	) {
	}
}
