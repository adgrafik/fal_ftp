<?php
namespace AdGrafik\FalFtp\FTPClient;

/***************************************************************
 * Copyright notice
 *
 * (c) 2014 Arno Dudek <webmaster@adgrafik.at>
 * All rights reserved
 * 
 * Some parts of FTP handling as special parsing the list results 
 * was adapted from net2ftp by David Gartner.
 * @see https://www.net2ftp.com
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

use \TYPO3\CMS\Core\Utility\GeneralUtility;
use \AdGrafik\FalFtp\FTPClient\AbstractFTP;
use \AdGrafik\FalFtp\FTPClient\Exception;

/**
 * FTP client.
 *
 * @author Arno Dudek <webmaster@adgrafik.at>
 * @author Jonas Temmen <jonas.temmen@artundweise.de>
 */
class FTP extends AbstractFTP {

	/**
	 * @var boolean
	 */
	const MODE_ACTIVE = FALSE;

	/**
	 * @var boolean
	 */
	const MODE_PASSIV = TRUE;

	/**
	 * @var boolean
	 */
	const TRANSFER_ASCII = FTP_ASCII;

	/**
	 * @var boolean
	 */
	const TRANSFER_BINARY = FTP_BINARY;

	/**
	 * @var bool
	 */
	protected $isConnected = FALSE;

	/**
	 * @var string $host
	 */
	protected $host;

	/**
	 * @var integer $port
	 */
	protected $port;

	/**
	 * @var string
	 */
	protected $username;

	/**
	 * @var string
	 */
	protected $password;

	/**
	 * @var bool
	 */
	protected $ssl;

	/**
	 * @var integer $timeout
	 */
	protected $timeout;

	/**
	 * @var boolean $passiveMode
	 */
	protected $passiveMode;

	/**
	 * @var boolean $transferMode
	 */
	protected $transferMode;

	/**
	 * @var string $basePath
	 */
	protected $basePath;

	/**
	 * Constructor
	 *
	 * @param array $settings
	 */
	public function __construct(array $settings) {

		$this->parserRegistry = GeneralUtility::makeInstance('AdGrafik\\FalFtp\\FTPClient\\ParserRegistry');
		if ($this->parserRegistry->hasParser() === FALSE) {
			$this->parserRegistry->registerParser(array(
				'AdGrafik\\FalFtp\\FTPClient\\Parser\\StrictRulesParser',
				'AdGrafik\\FalFtp\\FTPClient\\Parser\\LessStrictRulesParser',
				'AdGrafik\\FalFtp\\FTPClient\\Parser\\WindowsParser',
				'AdGrafik\\FalFtp\\FTPClient\\Parser\\NetwareParser',
				'AdGrafik\\FalFtp\\FTPClient\\Parser\\AS400Parser',
				'AdGrafik\\FalFtp\\FTPClient\\Parser\\TitanParser',
			));
		}

		$this->filterRegistry = GeneralUtility::makeInstance('AdGrafik\\FalFtp\\FTPClient\\FilterRegistry');
		if ($this->filterRegistry->hasFilter() === FALSE) {
			$this->filterRegistry->registerFilter(array(
				'AdGrafik\\FalFtp\\FTPClient\\Filter\\DotsFilter',
				'AdGrafik\\FalFtp\\FTPClient\\Filter\\StringTotalFilter',
			));
		}

		$extractorRegistry = GeneralUtility::makeInstance('TYPO3\CMS\Core\Resource\Index\ExtractorRegistry');
		$extractorRegistry->registerExtractionService("AdGrafik\FalFtp\Extractor\ImageDimensionExtractor");

		$this->host = urldecode(trim($settings['host'], '/') ?: '');
		$this->port = (integer) $settings['port'] ?: 21;
		$this->username = $settings['username'];
		$this->password = $settings['password'];
		$this->ssl = (bool)$settings['ssl'];
		$this->timeout = (integer) $settings['timeout'] ?: 90;
		$this->passiveMode = isset($settings['passiveMode']) ? (boolean) $settings['passiveMode'] : self::MODE_PASSIV;
		$this->transferMode = isset($settings['transferMode']) ? $settings['transferMode'] : self::TRANSFER_BINARY;
		$this->basePath = '/' . (trim($settings['basePath'], '/') ?: '');
	}

	/**
	 * Connect to the FTP server.
	 *
	 * @param string $username
	 * @param string $password
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 */
	public function connect($username = '', $password = '') {
		if ($this->isConnected) {
			return $this;
		}

		$this->stream = $this->ssl
			? @ftp_ssl_connect($this->host, $this->port, $this->timeout)
			: @ftp_connect($this->host, $this->port, $this->timeout);

		if ($this->stream === FALSE) {
			throw new Exception\InvalidConfigurationException('Couldn\'t connect to host "' . $this->host . ':' . $this->port . '".', 1408550516);
		}

		$this->isConnected = TRUE;

		if (!empty($username)) {
			$this->username = $username;
			$this->password = $password;
		}
		if ($this->username) {
			$this->login($this->username, $this->password)->setPassiveMode($this->passiveMode);
		}


		return $this;
	}

	/**
	 * Close the FTP connection.
	 *
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 */
	public function disconnect() {
		$result = @ftp_close($this->getStream());
		if ($result === FALSE) {
			throw new Exception\InvalidConfigurationException('Closeing connection faild.', 1408550517);
		}
		return $this;
	}

	/**
	 * Logs in to the FTP connection.
	 *
	 * @param string $username
	 * @param string $password
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidConfigurationException
	 */
	public function login($username = '', $password = '') {

		$username = $username ? urldecode($username) : 'anonymous';
		$password = $password ? urldecode($password) : '';

		$result = @ftp_login($this->getStream(), $username, $password);
		if ($result === FALSE) {
			throw new Exception\InvalidConfigurationException('Couldn\'t connect with username "' . $this->username . '".', 1408550518);
		}
		return $this;
	}

	/**
	 * Turns passive mode on or off.
	 *
	 * @param boolean $passiveMode
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function setPassiveMode($passiveMode) {
		$result = @ftp_pasv($this->getStream(), $this->passiveMode);
		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Setting passive mode faild.', 1408550519);
		}
		$this->passiveMode = (boolean) $passiveMode;
		return $this;
	}

	/**
	 * Returns TRUE if given directory or file exists.
	 *
	 * @param string $resource Remote directory or file, relative path from basePath.
	 * @return boolean
	 */
	public function resourceExists($resource) {
		if ($this->directoryExists($resource) === FALSE) {
			return $this->fileExists($resource);
		}
		return TRUE;
	}

	/**
	 * Returns the last modified time of the given file (or directory some times).
	 *
	 * @param string $resource Remote directory or file, relative path from basePath.
	 * @return integer
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function getModificationTime($resource) {
		$result = @ftp_mdtm($this->getStream(), $this->getAbsolutePath($resource));
		if ($result === -1) {
			throw new Exception\FTPConnectionException('Getting modification time of resource "' . $resource . '" failed.', 1408550520);
		}
		return $result;
	}

	/**
	 * Renames a directory or file on the FTP server.
	 *
	 * @param string $sourceResource Source remote directory or file, relative path from basePath.
	 * @param string $targetResource Target remote directory or file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function renameResource($sourceResource, $targetResource, $overwrite = FALSE) {

		if ($overwrite === FALSE && $this->resourceExists($targetResource)) {
			throw new Exception\ExistingResourceException('Resource "' . $sourceResource . '" already exists.', 1408550521);
		}

		$result = @ftp_rename($this->getStream(), $this->getAbsolutePath($sourceResource), $this->getAbsolutePath($targetResource));
		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Renaming resource "' . $sourceResource . '" to "' . $targetResource . '" failed.', 1408550522);
		}

		return $this;
	}

	/**
	 * Returns TRUE if given directory exists.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return boolean
	 */
	public function directoryExists($directory) {
		$result = @ftp_chdir($this->getStream(), $this->getAbsolutePath($directory));
		return $result;
	}

	/**
	 * Changes the current directory to the specified one.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidDirectoryException
	 */
	public function changeDirectory($directory) {

		$result = @ftp_chdir($this->getStream(), $this->getAbsolutePath($directory));
		if ($result === FALSE) {
			throw new Exception\InvalidDirectoryException('Changing directory "' . $directory . '" faild.', 1408550523);
		}
		return $this;
	}

	/**
	 * Changes the current directory to the parent directory.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\InvalidDirectoryException
	 */
	public function changeToParentDirectory($directory) {
		$result = @ftp_cdup($this->getStream());
		if ($result === FALSE) {
			throw new Exception\InvalidDirectoryException('Changing to parent directory from "' . $directory . '" faild.', 1408550524);
		}
		return $this;
	}

	/**
	 * Creates a directory.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function createDirectory($directory) {
		$result = @ftp_mkdir($this->getStream(), $this->getAbsolutePath($directory));
		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Creating directory "' . $directory . '" faild.', 1408550525);
		}
		return $this;
	}

	/**
	 * Renames a directory on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceDirectory Source remote directory, relative path from basePath.
	 * @param string $targetDirectory Target remote directory, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 */
	public function renameDirectory($sourceDirectory, $targetDirectory, $overwrite = FALSE) {
		return $this->renameResource($sourceDirectory, $targetDirectory, $overwrite);
	}

	/**
	 * Moves a directory on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceDirectory Source remote directory, relative path from basePath.
	 * @param string $targetDirectory Target remote directory, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 */
	public function moveDirectory($sourceDirectory, $targetDirectory, $overwrite = FALSE) {
		return $this->renameResource($sourceDirectory, $targetDirectory, $overwrite);
	}

	/**
	 * Copy a directory on the FTP server.
	 *
	 * @param string $sourceDirectory Source remote directory, relative path from basePath.
	 * @param string $targetDirectory Target remote directory, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 */
	public function copyDirectory($sourceDirectory, $targetDirectory, $overwrite = FALSE) {

		// If $overwrite is set to FALSE check only for the first directory. On recursion this parameter is by default TRUE.
		if ($overwrite === FALSE && $this->resourceExists($targetDirectory)) {
			throw new Exception\ExistingResourceException('Directory "' . $targetDirectory . '" already exists.', 1408550526);
		}

		$this->createDirectory($targetDirectory);

		$directoryList = $this->fetchDirectoryList($sourceDirectory);
		foreach ($directoryList as &$resourceInfo) {
			if ($resourceInfo['isDirectory']) {
				$this->copyDirectory($sourceDirectory . $resourceInfo['name'] . '/', $targetDirectory . $resourceInfo['name'] . '/', TRUE);
			} else {
				$this->copyFile($sourceDirectory . $resourceInfo['name'], $targetDirectory . $resourceInfo['name'], TRUE);
			}
		}

		return $this;
	}

	/**
	 * Moves a directory on the FTP server.
	 *
	 * @param string $directory Remote directory, relative path from basePath.
	 * @param boolean $recursively
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function deleteDirectory($directory, $recursively = TRUE) {

		$directoryList = $this->fetchDirectoryList($directory);

		foreach ($directoryList as &$resourceInfo) {
			if ($resourceInfo['isDirectory'] === FALSE) {
				$this->deleteFile($resourceInfo['path'] . $resourceInfo['name']);
			} else if ($recursively) {
				$this->deleteDirectory($resourceInfo['path'] . $resourceInfo['name'] . '/', $recursively);
			}
		}

		// The ftp_rmdir may not work with all FTP servers. Solution: to delete /dir/parent/dirtodelete
		// 1. chdir to the parent directory  /dir/parent
		// 2. delete the subdirectory, but use only its name (dirtodelete), not the full path (/dir/parent/dirtodelete)
		$parentDirectory = $this->getParentDirectory($directory);
		$this->changeDirectory($parentDirectory);

		$result = @ftp_rmdir($this->getStream(), $this->getResourceName($directory));
		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Deleting directory ' . $directory . ' failed.', 1408550527);
		}

		return $result;
	}

	/**
	 * Returns TRUE if given file exists.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return boolean
	 */
	public function fileExists($file) {
		$result = @ftp_size($this->getStream(), $this->getAbsolutePath($file));
		return ($result !== -1);
	}

	/**
	 * Returns the size of the given file.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return integer
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FileOperationErrorException
	 */
	public function getFileSize($file) {
		$result = @ftp_size($this->getStream(), $this->getAbsolutePath($file));
		if ($result === -1) {
			throw new Exception\FileOperationErrorException('Fetching file size of "' . $file . '" faild.', 1408550528);
		}
		return $result;
	}

	/**
	 * Uploads a file to the FTP server.
	 *
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param mixed $sourceFileOrResource Local source file or file resource, absolute path.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function uploadFile($targetFile, $sourceFileOrResource, $overwrite = FALSE) {

		if (is_resource($sourceFileOrResource) === FALSE && @is_file($sourceFileOrResource) === FALSE) {
			throw new Exception\ResourceDoesNotExistException('File "' . $sourceFileOrResource . '" not exists.', 1408550529);
		}

		if ($overwrite === FALSE && $this->resourceExists($targetFile)) {
			throw new Exception\ExistingResourceException('File "' . $targetFile . '" already exists.', 1408550530);
		}

		if (is_resource($sourceFileOrResource)) {
			rewind($sourceFileOrResource);
			$result = @ftp_fput($this->getStream(), $this->getAbsolutePath($targetFile), $sourceFileOrResource, $this->transferMode);
		} else {
			$result = @ftp_put($this->getStream(), $this->getAbsolutePath($targetFile), $sourceFileOrResource, $this->transferMode);
		}

		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Upload file "' . $targetFile . '" faild.', 1408550531);
		}

		return $this;
	}

	/**
	 * Download a file to a temporary file.
	 *
	 * @param string $sourceFile Target remote file, relative path from basePath.
	 * @param mixed $targetFileOrResource Local target file or file resource, absolute path.
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function downloadFile($sourceFile, $targetFileOrResource) {

		if (is_resource($targetFileOrResource) === FALSE && @is_file($targetFileOrResource) === FALSE) {
			throw new Exception\ResourceDoesNotExistException('File "' . $targetFileOrResource . '" not exists.', 1408550532);
		}

		if (is_resource($targetFileOrResource)) {
			$result = @ftp_fget($this->getStream(), $targetFileOrResource, $this->getAbsolutePath($sourceFile), $this->transferMode);
			rewind($targetFileOrResource);
		} else {
			$result = @ftp_get($this->getStream(), $targetFileOrResource, $this->getAbsolutePath($sourceFile), $this->transferMode);
		}

		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Download file "' . $sourceFile . '" faild.', 1408550533);
		}

		return $this;
	}

	/**
	 * Set the contents of a file.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @param string $contents
	 * @return integer
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FileOperationErrorException Thrown if writing temporary file fails.
	 */
	public function setFileContents($file, $contents) {

		$temporaryFile = tmpfile();

		$result = fwrite($temporaryFile, $contents);
		if ($result === FALSE) {
			throw new Exception\FileOperationErrorException('Writing temporary file for "' . $file . '" faild.', 1408550534);
		}

		$this->uploadFile($file, $temporaryFile, TRUE);

		fclose($temporaryFile);

		return $result;
	}

	/**
	 * Get the contents of a file.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return string
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function getFileContents($file) {

		$temporaryFile = tmpfile();

		$this->downloadFile($file, $temporaryFile);

		$result = stream_get_contents($temporaryFile);
		if ($result === FALSE) {
			throw new Exception\FileOperationErrorException('Reading temporary file for "' . $file . '" faild.', 1408550535);
		}

		fclose($temporaryFile);

		return $result;
	}

	/**
	 * Create a file on the FTP server.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function createFile($file, $overwrite = FALSE) {

		if ($overwrite === FALSE && $this->resourceExists($file)) {
			throw new Exception\ExistingResourceException('File "' . $file . '" already exists.', 1408550536);
		}

		$this->setFileContents($file, '');

		return $this;
	}

	/**
	 * Replace a file to the FTP server.
	 * Alias of uploadFile().
	 *
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param mixed $sourceFileOrResource Local source file or file resource, absolute path.
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 */
	public function replaceFile($targetFile, $sourceFileOrResource) {
		return $this->uploadFile($targetFile, $sourceFileOrResource, TRUE);
	}

	/**
	 * Renames a file on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceFile Source remote file, relative path from basePath.
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 */
	public function renameFile($sourceFile, $targetFile, $overwrite = FALSE) {
		return $this->renameResource($sourceFile, $targetFile, $overwrite);
	}

	/**
	 * Moves a file on the FTP server.
	 * Alias of renameResource().
	 *
	 * @param string $sourceFile Source remote file, relative path from basePath.
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 */
	public function moveFile($sourceFile, $targetFile, $overwrite = FALSE) {
		return $this->renameResource($sourceFile, $targetFile, $overwrite);
	}

	/**
	 * Copy a file on the FTP server.
	 *
	 * @param string $sourceFile Source remote file, relative path from basePath.
	 * @param string $targetFile Target remote file, relative path from basePath.
	 * @param boolean $overwrite
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 */
	public function copyFile($sourceFile, $targetFile, $overwrite = FALSE) {

		$temporaryFile = tmpfile();

		$this->downloadFile($sourceFile, $temporaryFile)
			 ->uploadFile($targetFile, $temporaryFile, $overwrite);

		fclose($temporaryFile);

		return $this;
	}

	/**
	 * Deletes a file on the FTP server.
	 *
	 * @param string $file Remote file, relative path from basePath.
	 * @return \AdGrafik\FalFtp\FTPClient\FTP
	 * @throws \AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException Thrown at FTP error.
	 */
	public function deleteFile($file) {
		$result = @ftp_delete($this->getStream(), $this->getAbsolutePath($file));
		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Deleting file "' . $file . '" faild.', 1408550537);
		}
		return $this;
	}

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
	public function fetchDirectoryList($directory, $resourceInfoParserCallback = NULL, $sort = 'strnatcasecmp') {

		$this->changeDirectory($directory);

		// The -a option is used to show the hidden files as well on some FTP servers.
		$result = @ftp_rawlist($this->getStream(), '-a ');
		if ($result === FALSE) {
			throw new Exception\FTPConnectionException('Fetching directory "' . $directory . '" faild.', 1408550538);
		}
		// Some servers do not return anything when using -a, so in that case try again without the -a option.
		if (sizeof($result) <= 1) {
			$result = @ftp_rawlist($this->getStream(), '');
			if ($result === FALSE) {
				throw new Exception\FTPConnectionException('Fetching directory "' . $directory . '" faild.', 1408550539);
			}
		}

		$resourceList = array();
		foreach ($result as &$resource) {

			$resourceInfo = array(
				'path' => $directory,
				'isDirectory' => NULL,
				'name' => NULL,
				'size' => NULL,
				'owner' => NULL,
				'group' => NULL,
				'mode' => NULL,
				'mimetype' => NULL,
				'mtime' => 0,
			);

			foreach ($this->parserRegistry->getParser() as $parserClass) {
				$parserObject = GeneralUtility::makeInstance($parserClass);
				if ($parseResult = $parserObject->parse($resourceInfo, $resource, $this)) {
					$resourceInfo['parseClass'] = $parserClass;
					break;
				}
			}

			// If nothing match throw exception.
			if ($parseResult === FALSE) {
				throw new Exception\InvalidConfigurationException('FTP format not supported.', 1408550540);
			}

			foreach ($this->filterRegistry->getFilter() as $filterClass) {
				$filterObject = GeneralUtility::makeInstance($filterClass);
				if ($filterObject->filter($resourceInfo, $resource, $this)) {
					continue 2;
				}
			}

			if ($resourceInfo['isDirectory'] === NULL) {
				throw new Exception\InvalidAttributeException('FTP resource attribute "isDirectory" can not be NULL.', 1408550541);
			}
			if ($resourceInfo['name'] === NULL || empty($resourceInfo['name'])) {
				throw new Exception\InvalidAttributeException('FTP resource attribute "name" can not be NULL or empty.', 1408550542);
			}

			if ($resourceInfoParserCallback) {
				$resourceInfoReference = &$resourceInfo;
				call_user_func($resourceInfoParserCallback, $resourceInfoReference, $this);
			}

			$resourceList[] = $resourceInfo;
		}

		if ($sort) {
			uksort($resourceList, $sort);
		}

		return $resourceList;
	}

}

?>