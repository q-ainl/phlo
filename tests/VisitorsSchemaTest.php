<?php
use PHPUnit\Framework\TestCase;

// Guards drift between the visitors resource and its shipped schema: every column
// the code declares, and the visitor_pages table the heartbeat writes, must exist
// in visitors.sql.
final class VisitorsSchemaTest extends TestCase {

	private function sqlColumns(string $sql, string $table):array {
		if (!preg_match('/CREATE TABLE `'.$table.'`\s*\((.*?)\n\)/s', $sql, $m)) return [];
		preg_match_all('/^\s*`(\w+)`/m', $m[1], $cols);
		return $cols[1];
	}

	public function testVisitorsSchemaMatchesCode():void {
		$code = (string)file_get_contents(engine.'resources/visitors.phlo');
		$sql  = (string)file_get_contents(engine.'resources/visitors.sql');

		preg_match("/static columns = '([^']+)'/", $code, $m);
		$codeCols = explode(',', $m[1] ?? '');
		$this->assertContains('active_seconds', $codeCols, 'sanity: code declares active_seconds');
		$sqlCols = $this->sqlColumns($sql, 'visitors');
		foreach ($codeCols as $col) $this->assertContains($col, $sqlCols, "visitors.sql is missing column `$col`");
		$this->assertNotContains('requests', $sqlCols, 'stale `requests` column must be gone');

		$pageCols = $this->sqlColumns($sql, 'visitor_pages');
		$this->assertNotEmpty($pageCols, 'visitors.sql must define the visitor_pages table');
		foreach (['id', 'visitor', 'host', 'page', 'lang', 'active_seconds', 'beats', 'created', 'changed'] as $col)
			$this->assertContains($col, $pageCols, "visitor_pages is missing column `$col`");
	}
}
