<?php
namespace phlo\tech;

class icons {
	public static function build(string|array $folders, string $buildPath, string $version = '1.0'):?string {
		$files = self::icon_files($folders);
		if (!$files) return null;
		$images = [];
		$width = 0;
		$height = 0;
		$topX = [];
		$topY = [];
		foreach ($files AS $file){
			$img = \imagecreatefrompng($file);
			$ix = \imagesx($img);
			$iy = \imagesy($img);
			@$topX[$ix]++;
			@$topY[$iy]++;
			$width += $ix;
			$height = \max($height, $iy);
			$images[\basename($file, '.png')] = $img;
		}
		\arsort($topX);
		\arsort($topY);
		$topX = key($topX);
		$topY = key($topY);
		$CSS = ".icon {\n".
		"\tbackground-image: url(/icons.png?$version);\n".
		"\tbackground-position-y: bottom;\n".
		"\tdisplay: inline-block;\n".
		"\toverflow: hidden;\n".
		"\tpadding: 0;\n".
		"\twidth: {$topX}px;\n".
		"\theight: {$topY}px;\n}";
		$icons = \imagecreatetruecolor($width, $height);
		\imagefill($icons, 0, 0, \imagecolorallocatealpha($icons, 0, 0, 0, 127));
		\imagealphablending($icons, false);
		\imagesavealpha($icons, true);
		$left = 0;
		foreach (array_reverse($images, true) AS $name => $img){
			\imagecopy($icons, $img, $left, $height - \imagesy($img), 0, 0, \imagesx($img), \imagesy($img));
			if (\preg_match('/(.+)\.(.+)$/', $name, $match)) $selector = "body.$match[2] .icon.$match[1]";
			else $selector = '.icon.'.$name;
			$il = $left ? \phlo\tab.'background-position-x: '.($left ? \phlo\dash.$left : '0').'px;'.\phlo\lf : \phlo\void;
			$iw = ($ix = \imagesx($img)) === $topX ? \phlo\void : "\twidth: {$ix}px;\n";
			$ih = ($iy = \imagesy($img)) === $topY ? \phlo\void : "\theight: {$iy}px;\n";
			($il || (!$match && $iw || $ih)) && $CSS .= \phlo\lf.$selector.' {'.\phlo\lf.$il.($match ? \phlo\void : $iw.$ih).'}';
			$left += \imagesx($img);
		}
		$filename = 'icons.png';
		$tempFile = \tempnam(\sys_get_temp_dir(), \phlo\void);
		$iconFile = \rtrim($buildPath, \phlo\slash).\phlo\slash.$filename;
		\imagepng($icons, $tempFile);
		if (\is_file($iconFile) && \md5_file($tempFile) === \md5_file($iconFile)) \unlink($tempFile);
		else {
			\rename($tempFile, $iconFile);
			\debug("$filename written");
		}
		return $CSS;
	}

	public static function icon_files(string|array $folders):array {
		$list = [];
		foreach ((array)$folders AS $folder){
			$folder = \rtrim($folder, \phlo\slash).\phlo\slash;
			if (!\is_dir($folder)) continue;
			foreach (\glob($folder.'*.png') AS $file) $list[] = $file;
		}
		return $list;
	}
}
