<?php
if (!defined ('TYPO3_MODE')) die ('Access denied.');

// Register list result parser. Hooks will be sort by key before!
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine'] = array(
	0 => 'AdGrafik\\FalFtp\\Hook\\ListParser->parseStrictRules',
	1 => 'AdGrafik\\FalFtp\\Hook\\ListParser->parseLessStrictRules',
	2 => 'AdGrafik\\FalFtp\\Hook\\ListParser->parseWindowsRules',
	3 => 'AdGrafik\\FalFtp\\Hook\\ListParser->parseNetwareRules',
	4 => 'AdGrafik\\FalFtp\\Hook\\ListParser->parseAS400Rules',
	5 => 'AdGrafik\\FalFtp\\Hook\\ListParser->parseTitanRules',
);

?>