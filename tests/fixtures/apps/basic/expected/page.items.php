<?php
// source:  %SRC%/page.items.phlo
// phlo:    %VERSION%
// summary: Items page fixture
class page_items extends obj {
	public $items = ['alpha', 'beta'];
	protected function label($x){
		return strtoupper($x);
	}
	public static function save(){
		return ['saved' => true];
	}
	protected function list($items):string {
		$_ = [];
		$_[] = "<ul class=\"list\">";
		foreach ($items AS $item){
			$_[] = "<li data-kind=\"item\">".$this->label($item)."</li>";
		}
		$_[] = "</ul>";
		return implode(lf, $_);
	}
}
