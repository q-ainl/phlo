<?php
// source:  %SRC%/app.phlo
// phlo:    %VERSION%
// summary: View compiler golden cases
class app extends obj {
	public $title = 'Views';
	public $ready = true;
	public $count = 2;
	protected function _items(){
		return ['a', 'b'];
	}
	protected function controller(){
		app::route();
	}
	public static function route():bool {
		return route('GET', cb: 'app::GETHome');
	}
	public static function GETHome(){
		return view(phlo('app')->main, 'Views');
	}
	protected function main():string {
		$_ = [];
		$_[] = "<h1 id=\"top\" class=\"hero big\">$this->title</h1>";
		$_[] = "<p>".ucfirst($this->title)."</p>";
		$_[] = "<p>".($this->ready ? 'ready' : 'not ready')."</p>";
		$_[] = "<a href=\"/x\" title=\"1 > 0\">literal gt in attribute value</a>";
		$_[] = "<div data-id=\"".$this->title."\">arrow operator inside attribute interpolation</div>";
		$_[] = "<div class=\"".($this->count > 0 ? 'has' : 'none')."\">gt inside dynamic attribute</div>";
		$_[] = "<span class='foo'>single quoted attribute</span>";
		$_[] = "<span class=\"bar\">bare unquoted attribute</span>";
		$_[] = "<input type=\"text\" value=\"hello\" required>";
		$_[] = "<div class=\"card extra\">shorthand class plus static class</div>";
		$_[] = "<div class=\"tier ".($this->ready ? 'on' : 'off')."\">shorthand class plus dynamic class</div>";
		$_[] = "<div class=\"".($this->ready ? "yes" : "no")."\">double-quoted strings inside a dynamic class</div>";
		$_[] = "<section class=\"wrap\" id=\"explicit\">shorthand id plus explicit id</section>";
		foreach ($this->items AS $item){
			$_[] = "<li class=\"row\">".$item."</li>";
		}
		if ($this->ready){
			$_[] = "<p class=\"on\">shown</p>";
		}
		else {
			$_[] = "<p class=\"off\">hidden</p>";
		}
		return implode(lf, $_);
	}
}
