<?php
if (!defined ('TYPO3_MODE')) die ('Access denied.');

$registry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Driver\\DriverRegistry');
$registry->registerDriverClass(
	'AdGrafik\\FalFtp\\FTPDriver',
	'FTP',
	'FTP filesystem',
	'FILE:EXT:fal_ftp/Configuration/FlexForm/FTPDriverFlexForm.xml'
);

?>