<?php

class build_icons {
	public static function build(string|array $folders, string $buildPath, string $version = '1.0'):?string {
		$files = self::icon_files($folders);
		if (!$files) return null;
		$images = [];
		$width  = 0;
		$height = 0;
		$topX   = [];
		$topY   = [];
		foreach ($files as $file){
			$img  = imagecreatefrompng($file);
			$ix   = imagesx($img);
			$iy   = imagesy($img);
			@$topX[$ix]++;
			@$topY[$iy]++;
			$width  += $ix;
			$height  = max($height, $iy);
			$images[basename($file, '.png')] = $img;
		}
		arsort($topX);
		arsort($topY);
		$topX = key($topX);
		$topY = key($topY);
		$CSS  = ".icon {\n"
			."\tbackground-image: url(/icons.png?$version);\n"
			."\tbackground-position-y: bottom;\n"
			."\tdisplay: inline-block;\n"
			."\toverflow: hidden;\n"
			."\tpadding: 0;\n"
			."\twidth: {$topX}px;\n"
			."\theight: {$topY}px;\n}";
		$icons = imagecreatetruecolor($width, $height);
		imagefill($icons, 0, 0, imagecolorallocatealpha($icons, 0, 0, 0, 127));
		imagealphablending($icons, false);
		imagesavealpha($icons, true);
		$left = 0;
		foreach (array_reverse($images, true) as $name => $img){
			imagecopy($icons, $img, $left, $height - imagesy($img), 0, 0, imagesx($img), imagesy($img));
			$match    = null;
			$selector = preg_match('/(.+)\.(.+)$/', $name, $match)
				? 'body.'.$match[2].' .icon.'.$match[1]
				: '.icon.'.$name;
			$il = $left ? tab.'background-position-x: '.($left ? dash.$left : '0')."px;\n" : void;
			$iw = ($ix = imagesx($img)) === $topX ? void : "\twidth: {$ix}px;\n";
			$ih = ($iy = imagesy($img)) === $topY ? void : "\theight: {$iy}px;\n";
			if ($il || (!$match && ($iw || $ih))) $CSS .= lf.$selector." {\n".$il.($match ? void : $iw.$ih).'}';
			$left += imagesx($img);
		}
		$tempFile = tempnam(sys_get_temp_dir(), void);
		$iconFile = rtrim($buildPath, slash).slash.'icons.png';
		imagepng($icons, $tempFile);
		if (is_file($iconFile) && md5_file($tempFile) === md5_file($iconFile)) unlink($tempFile);
		else rename($tempFile, $iconFile);
		return $CSS;
	}

	public static function icon_files(string|array $folders):array {
		$list = [];
		foreach ((array)$folders as $folder){
			$folder = rtrim($folder, slash).slash;
			if (!is_dir($folder)) continue;
			foreach (glob($folder.'*.png') ?: [] as $file) $list[] = $file;
		}
		return $list;
	}
}
