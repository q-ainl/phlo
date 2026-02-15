<?php
if (function_exists('opcache_compile_file')){
	opcache_compile_file(__DIR__.'/tech.php');
	opcache_compile_file(__DIR__.'/build.php');
	opcache_compile_file(__DIR__.'/constants.php');
	opcache_compile_file(__DIR__.'/debug.php');
	opcache_compile_file(__DIR__.'/functions.php');
	opcache_compile_file(__DIR__.'/classes/obj.php');
	opcache_compile_file(__DIR__.'/classes/req.php');
	opcache_compile_file(__DIR__.'/classes/res.php');
}
return true;
