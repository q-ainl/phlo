<?php
// source:  %RSRC%/widget.phlo
// phlo:    %VERSION%
// summary: Widget resource fixture
class widget extends obj {
	public static $theme = 'light';
	protected function render(){
		return '<div class="widget '.static::$theme.'">W</div>';
	}
}
