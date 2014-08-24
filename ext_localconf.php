<?php
if (!defined ('TYPO3_MODE')) die ('Access denied.');

$registerDriver = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Driver\\DriverRegistry');
$registerDriver->registerDriverClass(
	'AdGrafik\\FalFtp\\Driver\\FTPDriver',
	'FTP',
	'FTP filesystem',
	'FILE:EXT:fal_ftp/Configuration/FlexForm/FTPDriver.xml'
);
$registerDriver->registerDriverClass(
	'AdGrafik\\FalFtp\\Driver\\FTPSDriver',
	'FTPS',
	'FTP-SSL filesystem',
	'FILE:EXT:fal_ftp/Configuration/FlexForm/FTPDriver.xml'
);

?>