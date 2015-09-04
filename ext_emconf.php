<?php

/***************************************************************
 * Extension Manager/Repository config file for ext "fal_ftp".
 *
 * Auto generated 24-08-2014 10:06
 *
 * Manual updates:
 * Only the data in the array - everything else is removed by next
 * writing. "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = array (
	'title' => 'FAL FTP Driver',
	'description' => 'Provides a FTP and FTP-SSL driver for the TYPO3 File Abstraction Layer (FAL) to manage files via filemanager (filelist).',
	'category' => 'plugin',
	'shy' => 0,
	'version' => '1.2.0',
	'dependencies' => 'cms,version',
	'conflicts' => '',
	'priority' => '',
	'loadOrder' => '',
	'module' => '',
	'state' => 'stable',
	'uploadfolder' => 0,
	'createDirs' => '',
	'modify_tables' => '',
	'clearcacheonload' => 1,
	'lockType' => '',
	'author' => 'Arno Dudek',
	'author_email' => 'webmaster@adgrafik.at',
	'author_company' => 'ad:grafik',
	'CGLcompliance' => NULL,
	'CGLcompliance_note' => NULL,
	'constraints' => 
	array (
		'depends' => 
		array (
			'cms' => '',
			'version' => '',
			'php' => '5.3.3-0.0.0',
			'typo3' => '7.0.0-7.3.99',
		),
		'conflicts' => 
		array (
		),
		'suggests' => 
		array (
		),
	),
	'suggests' => 
	array (
	),
);

?>