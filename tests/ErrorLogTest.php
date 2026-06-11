<?php
use PHPUnit\Framework\TestCase;

final class ErrorLogTest extends TestCase {

	private string $file;

	protected function setUp():void {
		$this->file = data.'errors.json';
		if (is_file($this->file)) unlink($this->file);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/log-test';
		phlo('tech/reset');
	}

	protected function tearDown():void {
		if (is_file($this->file)) unlink($this->file);
	}

	private function read():array {
		return json_decode((string)file_get_contents($this->file), true) ?: [];
	}

	public function testFirstLogCreatesEntry():void {
		phlo_error_log('app.phlo:10', 'Boom at /srv/app/app.phlo:10');
		$map = $this->read();
		$this->assertCount(1, $map);
		$row = current($map);
		$this->assertSame('app.phlo:10', $row['file']);
		$this->assertSame(1, $row['count']);
		$this->assertSame('log-test', $row['path']);
	}

	public function testRepeatedErrorDedupesAndCounts():void {
		phlo_error_log('app.phlo:10', 'Boom at /srv/app/app.phlo:10');
		phlo_error_log('app.phlo:10', 'Boom at /other/app.phlo:10');
		$map = $this->read();
		$this->assertCount(1, $map, 'Same origin must dedupe regardless of path noise in the message');
		$this->assertSame(2, current($map)['count']);
	}

	public function testNewestEntryIsFirst():void {
		phlo_error_log('a.phlo:1', 'First at /a.phlo:1');
		phlo_error_log('b.phlo:2', 'Second at /b.phlo:2');
		$map = $this->read();
		$this->assertSame('b.phlo:2', current($map)['file']);
	}

	public function testCapKeepsNewest200():void {
		for ($i = 0; $i < 205; $i++) phlo_error_log("f$i.phlo:$i", "Err $i at /f$i.phlo:$i");
		$map = $this->read();
		$this->assertCount(200, $map);
		$this->assertSame('f204.phlo:204', current($map)['file']);
	}

	public function testConcurrentWritesDoNotLoseUpdates():void {
		$worker = __DIR__.'/workers/errorlog.php';
		$procs  = [];
		$count  = 25;
		for ($i = 0; $i < $count; $i++){
			$procs[] = proc_open([PHP_BINARY, $worker, (string)$i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		}
		foreach ($procs as $p) proc_close($p);
		$map = $this->read();
		$this->assertCount($count, $map, 'flock must prevent lost updates under concurrent logging');
	}
}
