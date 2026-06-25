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

	// errors.json is keyed by the short id; the handler computes it once and passes it in.
	private function log(string $path, string $msg):int|false {
		return phlo_error_log(phlo_error_id((string)phlo('req')->host, $path, $msg), $path, $msg);
	}

	public function testErrorIdIsShortStableAndStripsPathNoise():void {
		$host = (string)phlo('req')->host;
		$a = phlo_error_id($host, 'app.phlo:10', 'Boom at /srv/app/app.phlo:10');
		$b = phlo_error_id($host, 'app.phlo:10', 'Boom at /other/path/app.phlo:10');
		$this->assertSame(8, strlen($a), 'a short 8-char reference, quotable from a production page');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $a);
		$this->assertSame($a, $b, 'path noise in the message must not change the id, so occurrences dedupe');
		$this->assertNotSame($a, phlo_error_id($host, 'other.phlo:5', 'Boom at /srv/app/app.phlo:10'), 'a different origin yields a different id');
	}

	public function testFirstLogCreatesEntry():void {
		$this->log('app.phlo:10', 'Boom at /srv/app/app.phlo:10');
		$map = $this->read();
		$this->assertCount(1, $map);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', array_key_first($map), 'the entry is keyed by the short id');
		$row = current($map);
		$this->assertSame('app.phlo:10', $row['file']);
		$this->assertSame(1, $row['count']);
		$this->assertSame('log-test', $row['path']);
	}

	public function testRepeatedErrorDedupesAndCounts():void {
		$this->log('app.phlo:10', 'Boom at /srv/app/app.phlo:10');
		$this->log('app.phlo:10', 'Boom at /other/app.phlo:10');
		$map = $this->read();
		$this->assertCount(1, $map, 'Same origin must dedupe regardless of path noise in the message');
		$this->assertSame(2, current($map)['count']);
	}

	public function testNewestEntryIsFirst():void {
		$this->log('a.phlo:1', 'First at /a.phlo:1');
		$this->log('b.phlo:2', 'Second at /b.phlo:2');
		$map = $this->read();
		$this->assertSame('b.phlo:2', current($map)['file']);
	}

	public function testCapKeepsNewest200():void {
		for ($i = 0; $i < 205; $i++) $this->log("f$i.phlo:$i", "Err $i at /f$i.phlo:$i");
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
