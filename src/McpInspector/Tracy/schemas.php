<?php declare(strict_types=1);

namespace Nette\McpInspector\Tracy\Schemas;


/**
 * One line of a Tracy log.
 */
final class LogEntry
{
	/**
	 * @param  ?string  $time  null when the line has no Tracy timestamp
	 * @param  ?string  $source  URL or CLI command that produced the entry
	 * @param  ?string  $report  name of the markdown exception report for tracy_get_report, null when there is none
	 */
	public function __construct(
		public readonly ?string $time,
		public readonly string $message,
		public readonly ?string $source,
		public readonly ?string $report,
	) {
	}
}


/**
 * Result of tracy_get_log.
 */
final class Log
{
	/**
	 * @param  list<LogEntry>  $entries  newest first
	 * @param  int  $total  number of lines in the whole log file
	 */
	public function __construct(
		public readonly string $level,
		public readonly array $entries,
		public readonly int $count,
		public readonly int $total,
	) {
	}
}


/**
 * Result of tracy_get_report.
 */
final class Report
{
	/**
	 * @param  string  $content  the markdown report, secrets replaced by ***
	 * @param  bool  $truncated  true when the content was cut at maxLength
	 */
	public function __construct(
		public readonly string $report,
		public readonly string $content,
		public readonly bool $truncated,
	) {
	}
}
