<?php declare(strict_types=1);

namespace Nette\McpInspector\Tracy\Schemas;


/**
 * One line of a Tracy log.
 */
final readonly class LogEntry
{
	public function __construct(
		/** null when the line has no Tracy timestamp */
		public ?string $time,
		public string $message,
		/** URL or CLI command that produced the entry */
		public ?string $source,
		/** name of the markdown exception report for tracy_get_report, null when there is none */
		public ?string $report,
	) {
	}
}


/**
 * Result of tracy_get_log.
 */
final readonly class Log
{
	public function __construct(
		public string $level,
		/** @var list<LogEntry> newest first */
		public array $entries,
		public int $count,
		/** number of lines in the whole log file */
		public int $total,
	) {
	}
}


/**
 * Result of tracy_get_report.
 */
final readonly class Report
{
	public function __construct(
		public string $report,
		/** the markdown report, secrets replaced by *** */
		public string $content,
		/** true when the content was cut at maxLength */
		public bool $truncated,
	) {
	}
}
