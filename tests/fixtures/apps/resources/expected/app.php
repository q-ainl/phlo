<?php
// source:  %SRC%/app.phlo
// phlo:    %VERSION%
// summary: Resource fixture app
class app extends obj {
	public static function route():bool {
		return route('GET', 'w', cb: 'app::GETW');
	}
	public $title = 'ResFix';
	public static function GETW(){
		return phlo('widget')->render;
	}
	protected function slugTitle(){
		return slug($this->title);
	}
	protected function shell():string {
		$_ = [];
		$_[] = "<main>".(phlo('widget')->render)."</main>";
		$_[] = "<p>".(camel('hello there'))."</p>";
		return implode(lf, $_);
	}
}
