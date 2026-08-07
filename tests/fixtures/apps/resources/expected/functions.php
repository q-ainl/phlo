<?php

function camel(string $text):string {
	return lcfirst(str_replace(space, void, ucwords(lcfirst($text))));
}

function slug(string $text):string {
	return trim(preg_replace('/[^a-z0-9]+/', dash, strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text))), dash);
}

