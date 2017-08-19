<?php

/*
 * This plugin will find all instances of the function getAllTranslations() and extract the text.
 *
 * It will insert the text into gettext() calls and include within this source code.
 *
 * @Copyright 2017 by Stephen L Billard for use in {@link https://github.com/ZenPhoto20/ZenPhoto20 ZenPhoto20}
 *
 * @author Stephen Billard (sbillard)
 * @package plugins
 * @subpackage admin
 * @category ZenPhoto20Tools
 */

// force UTF-8 Ø

define("OFFSET_PATH", 3);
require_once(dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME']))) . "/zp-core/admin-functions.php");

/**
 *
 * enumerates the files in folder(s)
 * @param $folder
 */
function getPHPFiles($folder) {
	global $scripts;
	$dirs = scandir($folder);
	$localfiles = array();
	$localfolders = array();
	foreach ($dirs as $file) {
		if ($file{0} != '.') {
			$file = str_replace('\\', '/', $file);
			$key = str_replace(SERVERPATH . '/', '', filesystemToInternal($folder . '/' . $file));
			if (is_dir($folder . '/' . $file)) {
				$localfolders = array_merge($localfolders, getPHPFiles($folder . '/' . $file));
			} else {
				if (getSuffix($key) == 'php') {
					$localfiles[] = $key;
				}
			}
		}
	}
	return array_merge($localfiles, $localfolders);
}

$scripts = array();
foreach ($_zp_gallery->getThemes() as $theme => $data) {
	if (protectedTheme($theme, true)) {
		$scripts = array_merge($scripts, getPHPFiles(SERVERPATH . '/' . THEMEFOLDER . '/' . $theme));
	}
}

$paths = getPluginFiles('*.php');
foreach ($paths as $plugin => $path) {
	if (strpos($path, USER_PLUGIN_FOLDER) !== false) {
		$p = file_get_contents($path);
		$i = strpos($p, '* @category');
		if (($key = $i) !== false) {
			$key = strtolower(trim(substr($p, $i + 11, strpos($p, "\n", $i) - $i - 11)));
			if ($key == 'package') {
				if (is_dir($dir = stripSuffix($path))) {
					$scripts = array_merge($scripts, getPHPFiles($dir));
				}
				$scripts[] = str_replace(SERVERPATH . '/', '', $path);
			}
		}
	}
}

$scripts = array_merge($scripts, getPHPFiles(SERVERPATH . '/' . ZENFOLDER));

$f = fopen(SERVERPATH . '/' . ZENFOLDER . '/allTranslations.php', 'w');
fwrite($f, "<?php\n/* This file contains language strings extracted from getAllTranslations() function calls.\n * it is used by Poedit to capture the strings for translation.\n */\n");

foreach ($scripts as $filename) {
	$content = file_get_contents(SERVERPATH . '/' . internalToFilesystem($filename));
	preg_match_all('~getAllTranslations\s*\(\s*([\'"])(.+?)\1\s*\)~is', $content, $matches);
	if (isset($matches[2]) && !empty($matches[2])) {
		fwrite($f, "\n/* $filename */\n");
		foreach ($matches[2] as $key => $text) {
			$text = $matches[1][$key] . $text . $matches[1][$key];
			fwrite($f, "gettext($text);\n");
		}
	}
}
fwrite($f, '?>');

header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?action=external&msg=getAllTranslations() updated.');
exitZP();