<?php
namespace AdGrafik\FalFtp\FTPClient;

/***************************************************************
 * Copyright notice
 *
 * (c) 2014 Arno Dudek <webmaster@adgrafik.at>
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * A copy is found in the textfile GPL.txt and important notices to the license
 * from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/


interface FTPInterface {

	/**
	 * Constructor
	 *
	 * @param array $settings
	 */
	public function __construct(array $settings);

	/**
	 * Connect to the FTP server.
	 *
	 * @param string $username
	 * @param string $password
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 */
	public function connect($username = '', $password = '');

	/**
	 * Close the FTP connection.
	 *
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 */
	public function disconnect();

	/**
	 * Logs in to the FTP connection.
	 *
	 * @param string $username
	 * @param string $password
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 */
	public function login($username, $password);

	/**
	 * Returns TRUE if given directory or file exists.
	 *
	 * @param string $resource Remote directory or file, relative path from basePath.
	 * @return boolean
	 */
	public function resourceExists($resource);

	/**
	 * Renames a directory or file on the FTP server.
	 *
	 * @param string $sourceResource Source remote directory or file, relative path from basePath.
	 * @param string $targetResource Target remote directory or file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function renameResource($sourceResource, $targetResource, $overwrite = FALSE);

	/**
	 * Returns TRUE if given directory exists.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return boolean
	 */
	public function directoryExists($directory);

	/**
	 * Changes the current directory to the specified one.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidDirectoryException
	 */
	public function changeDirectory($directory);

	/**
	 * Changes the current directory to the parent directory.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidDirectoryException
	 */
	public function changeToParentDirectory($directory);

	/**
	 * Creates a directory.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function createDirectory($directory);

	/**
	 * Renames a directory on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceDirectory Source remote directory, relative path from basePath.
	 * @param string $targetDirectory Target remote directory, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 */
	public function renameDirectory($sourceDirectory, $targetDirectory, $overwrite = FALSE);

	/**
	 * Moves a directory on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceDirectory Source remote directory, relative path from basePath.
	 * @param string $targetDirectory Target remote directory, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 */
	public function moveDirectory($sourceDirectory, $targetDirectory, $overwrite = FALSE);

	/**
	 * Copy a directory on the FTP server.
	 *
	 * @param string $sourceDirectory Source remote directory, relative path from basePath.
	 * @param string $targetDirectory Target remote directory, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 */
	public function copyDirectory($sourceDirectory, $targetDirectory, $overwrite = FALSE);

	/**
	 * Moves a directory on the FTP server.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @param boolean $recursively
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function deleteDirectory($directory, $recursively = TRUE);

	/**
	 * Returns TRUE if given file exists.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return boolean
	 */
	public function fileExists($file);

	/**
	 * Returns the size of the given file.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return integer
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FileOperationErrorException
	 */
	public function getFileSize($file);

	/**
	 * Uploads a file to the FTP server.
	 *
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param mixed $sourceFileOrResource Local source file or file resource, absolute path.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function uploadFile($targetFile, $sourceFileOrResource, $overwrite = FALSE);

	/**
	 * Download a file to a temporary file.
	 *
	 * @param string $sourceFile Target remote file, relative path from basePath.
	 * @param mixed $targetFileOrResource Local target file or file resource, absolute path.
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function downloadFile($sourceFile, $targetFileOrResource);

	/**
	 * Set the contents of a file.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @param string $contents
	 * @return integer
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FileOperationErrorException Thrown if writing temporary file fails.
	 */
	public function setFileContents($file, $contents);

	/**
	 * Get the contents of a file.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return string
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function getFileContents($file);

	/**
	 * Create a file on the FTP server.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function createFile($file, $overwrite = FALSE);

	/**
	 * Replace a file to the FTP server.
	 * Alias of uploadFile().
	 *
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param mixed $sourceFileOrResource Local source file or file resource, absolute path.
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 */
	public function replaceFile($targetFile, $sourceFileOrResource);

	/**
	 * Renames a file on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceFile Source remote file, relative path from basePath.
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 */
	public function renameFile($sourceFile, $targetFile, $overwrite = FALSE);

	/**
	 * Moves a file on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceFile Source remote file, relative path from basePath.
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 */
	public function moveFile($sourceFile, $targetFile, $overwrite = FALSE);

	/**
	 * Copy a file on the FTP server.
	 *
	 * @param string $sourceFile Source remote file, relative path from basePath.
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 */
	public function copyFile($sourceFile, $targetFile, $overwrite = FALSE);

	/**
	 * Deletes a file on the FTP server.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTPClient
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function deleteFile($file);

	/**
	 * Scans an ftp_rawlist line string and returns its parts (directory/file, name, size,...) using preg_match()
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @param mixed $resourceInfoParserCallback Either an array of object and method name or a function name.
	 * @param string $sort
	 * @return array
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidAttributeException
	 */
	public function fetchDirectoryList($directory, $resourceInfoParserCallback = NULL, $sort = 'strnatcasecmp');

}

?>