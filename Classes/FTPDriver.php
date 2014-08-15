<?php
namespace AdGrafik\FalFtp;

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

use TYPO3\CMS\Core\Utility\PathUtility;

class FTPDriver extends \TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver {

	/**
	 * A list of all supported hash algorithms, written all lower case and
	 * without any dashes etc. (e.g. sha1 instead of SHA-1)
	 * Be sure to set this in inherited classes!
	 *
	 * @var array
	 */
	protected $supportedHashAlgorithms = array('sha1', 'md5');

	/**
	 * The $directoryCache caches all files including file info which are loaded via FTP for current processing only.
	 *
	 * @var array $directoryCache
	 */
	protected $directoryCache;

	/**
	 * @var string $basePath
	 */
	protected $basePath;

	/**
	 * @var array $publicUrl
	 */
	protected $publicUrl;

	/**
	 * @var resource $stream
	 */
	protected $stream;

	/**
	 * @param array $configuration
	 */
	public function __construct(array $configuration = array()) {
		parent::__construct($configuration);
		// The capabilities default of this driver. See CAPABILITY_* constants for possible values
		$this->capabilities =
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_BROWSABLE |
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_PUBLIC |
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_WRITABLE;
		$this->directoryCache = array();
	}

	/**
	 * Initializes this object. This is called by the storage after the driver
	 * has been attached.
	 *
	 * @return void
	 */
	public function initialize() {
	}

	/**
	 * Merges the capabilites merged by the user at the storage
	 * configuration into the actual capabilities of the driver
	 * and returns the result.
	 *
	 * @param integer $capabilities
	 * @return integer
	 */
	public function mergeConfigurationCapabilities($capabilities) {
		$this->capabilities &= $capabilities;
		return $this->capabilities;
	}

	/**
	 * Processes the configuration, should be overridden by subclasses.
	 *
	 * @return void
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException
	 */
	public function processConfiguration() {
		$host = trim($this->configuration['host'], '/');
		$port = intval($this->configuration['port']);
		$mode = $this->configuration['mode'];
		$username = $this->configuration['username'];
		$password = $this->configuration['password'];

		$this->basePath = '/' . trim($this->configuration['basePath'], '/');
		$this->publicUrl = trim($this->configuration['publicUrl'], '/');

		$this->stream = @ftp_connect($host, $port);
		if ($this->stream === FALSE) {
			$this->addFlashMessage('Couldn\'t connect to host "' . $host . ':' . $port . '".');
			throw new \TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException('Couldn\'t connect to host "' . $host . ':' . $port . '".', 1407049621);
		}

		if ($mode === 'passiv') {
			$result = @ftp_pasv($this->stream, $passiveMode);
			if ($result === FALSE) {
				$this->addFlashMessage('Setting passive mode faild.', \TYPO3\CMS\Core\Messaging\FlashMessage::WARNING);
			}
		}

		// Direct login if username is not empty.
		if ($username) {
			$result = @ftp_login($this->stream, $username, $password);
			if ($result === FALSE) {
				$this->addFlashMessage('Couldn\'t connect with username "' . $username . '".');
				throw new \TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException('Couldn\'t connect with username "' . $username . '".', 1407049622);
			}
		}
	}

	/**
	 * Get stream
	 *
	 * @return resource
	 */
	public function getStream() {
		return $this->stream;
	}

	/**
	 * Returns the public URL to a file.
	 * For the local driver, this will always return a path relative to PATH_site.
	 *
	 * @param string $identifier
	 * @return string
	 */
	public function getPublicUrl($identifier) {
		return $this->publicUrl . $identifier;
	}

	/**
	 * Returns the identifier of the root level folder of the storage.
	 *
	 * @return string
	 */
	public function getRootLevelFolder() {
		return '/';
	}

	/**
	 * Returns the identifier of the default folder new files should be put into.
	 *
	 * @return string
	 */
	public function getDefaultFolder() {
		$folderIdentifier = '/user_upload/';
		if ($this->folderExists($folderIdentifier) === FALSE) {
			$folderIdentifier = $this->createFolder('user_upload', '/');
		}
		return $folderIdentifier;
	}

	/**
	 * Checks if a file exists.
	 *
	 * @param string $fileIdentifier
	 * @return boolean
	 * @throws \RuntimeException
	 */
	public function fileExists($fileIdentifier) {
		$result = @ftp_size($this->stream, $this->getAbsolutePath($fileIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Fetching size of file "' . $fileIdentifier . '" faild.', 1407049650);
		}
		return ($result !== -1);

	}

	/**
	 * Checks if a file inside a folder exists
	 *
	 * @param string $fileName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function fileExistsInFolder($fileName, $folderIdentifier) {
		return $this->fileExists($folderIdentifier . $fileName);
	}

	/**
	 * Returns information about a file.
	 *
	 * @param string $fileIdentifier
	 * @param array $propertiesToExtract Array of properties which are be extracted. If empty all will be extracted
	 * @return array
	 */
	public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = array()) {
		$folderIdentifier = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
		if (isset($this->directoryCache[$folderIdentifier][$fileIdentifier]) === FALSE) {
			// If not found try to load again.
			$this->fetchDirectory($folderIdentifier, TRUE);
			if (isset($this->directoryCache[$folderIdentifier][$fileIdentifier]) === FALSE) {
				$this->directoryCache[$folderIdentifier][$fileIdentifier] = array();
			}
		}

		return count($propertiesToExtract)
			? array_intersect_key(array_flip($propertiesToExtract), $this->directoryCache[$folderIdentifier][$fileIdentifier])
			: $this->directoryCache[$folderIdentifier][$fileIdentifier];
	}

	/**
	 * Returns a list of files inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array $filenameFilterCallbacks The method callbacks to use for filtering the items
	 * @return array of FileIdentifiers
	 */
	public function getFilesInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $filenameFilterCallbacks = array()) {
		return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $filenameFilterCallbacks, TRUE, FALSE, $recursive);
	}

	/**
	 * Adds a file from the local server hard disk to a given path in TYPO3s
	 * virtual file system. This assumes that the local file exists, so no
	 * further check is done here! After a successful the original file must
	 * not exist anymore.
	 *
	 * @param string $localFilePath (within PATH_site)
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName optional, if not given original name is used
	 * @param boolean $removeOriginal if set the original file will be removed after successful operation
	 * @return string the identifier of the new file
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 * @throws \RuntimeException
	 */
	public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = TRUE) {

		$newFileName = $this->sanitizeFileName($newFileName);
		$newFileIdentifier = $targetFolderIdentifier . $newFileName;

		$result = @ftp_put($this->stream, $this->getAbsolutePath($newFileIdentifier), $localFilePath, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Unable to upload file "' . $fileIdentifier . '".', 1407049655);
		}

		if ($removeOriginal) {
			unlink($localFilePath);
		}

		$this->fetchDirectory($targetFolderIdentifier);

		return $newFileIdentifier;
	}

	/**
	 * Creates a new (empty) file and returns the identifier.
	 *
	 * @param string $fileName
	 * @param string $parentFolderIdentifier
	 * @return string
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException
	 * @throws \RuntimeException
	 */
	public function createFile($fileName, $parentFolderIdentifier) {

		if ($this->isValidFilename($fileName) === FALSE) {
			throw new \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException('Invalid characters in fileName "' . $fileName . '"', 1320572272);
		}

		$fileName = $this->sanitizeFileName($fileName);
		$fileIdentifier = $parentFolderIdentifier . $fileName;
		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);

		$result = @ftp_put($this->stream, $this->getAbsolutePath($fileIdentifier), $temporaryFile, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Creating file ' . $fileIdentifier . ' failed.', 1320569854);
		}

		return $fileIdentifier;
	}

	/**
	 * Renames a file in this storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $newName The target path (including the file name!)
	 * @return string The identifier of the file after renaming
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 * @throws \RuntimeException
	 */
	public function renameFile($fileIdentifier, $newName) {

		$newName = $this->sanitizeFileName($newName);
		$folderIdentifier = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
		$newFileIdentifier = $folderIdentifier . $newName;

		$result = @ftp_rename($this->stream, $this->getAbsolutePath($fileIdentifier), $this->getAbsolutePath($newFileIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Renaming file ' . $fileIdentifier . ' to ' . $newFileIdentifier . ' failed.', 1320375115);
		}

		return $newFileIdentifier;
	}

	/**
	 * Replaces the contents (and file-specific metadata) of a file object with a local file.
	 *
	 * @param string $fileIdentifier
	 * @param string $localFilePath
	 * @return boolean TRUE if the operation succeeded
	 * @throws \RuntimeException
	 */
	public function replaceFile($fileIdentifier, $localFilePath) {
		$result = @ftp_put($this->stream, $this->getAbsolutePath($fileIdentifier), $localFilePath, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Unable to upload file "' . $fileIdentifier . '".', 1407049655);
		}
		return $newFileIdentifier;
	}

	/**
	 * Removes a file from the filesystem. This does not check if the file is
	 * still used or if it is a bad idea to delete it for some other reason
	 * this has to be taken care of in the upper layers (e.g. the Storage)!
	 *
	 * @param string $fileIdentifier
	 * @return boolean TRUE if deleting the file succeeded
	 * @throws \RuntimeException
	 */
	public function deleteFile($fileIdentifier) {
		$result = @ftp_delete($this->stream, $this->getAbsolutePath($fileIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Deletion of file ' . $fileIdentifier . ' failed.', 1320855304);
		}
		$this->fetchDirectory($this->getParentFolderIdentifierOfIdentifier($fileIdentifier), TRUE);
		return $result;
	}

	/**
	 * Sets the contents of a file to the specified value.
	 *
	 * @param string $fileIdentifier
	 * @param string $contents
	 * @return integer The number of bytes written to the file
	 * @throws \RuntimeException
	 */
	public function setFileContents($fileIdentifier, $contents) {
		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);
		$bytes = file_put_contents($temporaryFile, $contents);
		if (@ftp_put($this->stream, $this->getAbsolutePath($fileIdentifier), $temporaryFile, FTP_BINARY) === FALSE) {
			throw new \RuntimeException('Unable to upload file "' . $fileIdentifier . '".', 1407049655);
		}
		unlink($temporaryFile);
		return $bytes;
	}

	/**
	 * Returns the contents of a file. Beware that this requires to load the
	 * complete file into memory and also may require fetching the file from an
	 * external location. So this might be an expensive operation (both in terms
	 * of processing resources and money) for large files.
	 *
	 * @param string $fileIdentifier
	 * @return string The file contents
	 * @throws \RuntimeException
	 */
	public function getFileContents($fileIdentifier) {
		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);
		if (@ftp_get($this->stream, $temporaryFile, $this->getAbsolutePath($fileIdentifier), FTP_BINARY) === FALSE) {
			throw new \RuntimeException('Unable to read file "' . $fileIdentifier . '".', 1407049655);
		}
		$contents = file_get_contents($temporaryFile);
		unlink($temporaryFile);
		return $contents;
	}

	/**
	 * Returns a path to a local copy of a file for processing it. When changing the
	 * file, you have to take care of replacing the current version yourself!
	 *
	 * @param string $fileIdentifier
	 * @param bool $writable Set this to FALSE if you only need the file for read operations. This might speed up things,
	 *                       e.g. by using a cached local version. Never modify the file if you have set this flag!
	 * @return string The path to the file on the local disk
	 * @throws \RuntimeException
	 */
	public function getFileForLocalProcessing($fileIdentifier, $writable = TRUE) {
		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);
		$result = @ftp_get($this->stream, $temporaryFile, $this->getAbsolutePath($fileIdentifier), FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Unable to read file "' . $fileIdentifier . '".', 1407049655);
		}
		return $temporaryFile;
	}

	/**
	 * Directly output the contents of the file to the output
	 * buffer. Should not take care of header files or flushing
	 * buffer before. Will be taken care of by the Storage.
	 *
	 * @param string $identifier
	 *
	 * @return void
	 */
	public function dumpFileContents($identifier) {
		echo $this->getFileContents($this->getAbsolutePath($identifier));
	}

	/**
	 * Moves a file *within* the current storage.
	 * Note that this is only about an inner-storage move action,
	 * where a file is just moved to another folder in the same storage.
	 *
	 * @param string $sourceFileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName
	 * @return string
	 * @throws \RuntimeException
	 */
	public function moveFileWithinStorage($sourceFileIdentifier, $targetFolderIdentifier, $newFileName) {

		$targetFileIdentifier = $targetFolderIdentifier . $newFileName;

		$result = @ftp_rename($this->stream, $this->getAbsolutePath($sourceFileIdentifier), $this->getAbsolutePath($targetFileIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Moving file from ' . $sourceFileIdentifier . ' to ' . $targetFileIdentifier . ' failed.', 1320375195);
		}

		$this->fetchDirectory($this->getParentFolderIdentifierOfIdentifier($sourceFileIdentifier), TRUE);

		return $targetFileIdentifier;
	}

	/**
	 * Copies a file *within* the current storage.
	 * Note that this is only about an inner storage copy action,
	 * where a file is just copied to another folder in the same storage.
	 *
	 * @param string $sourceFileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $fileName
	 * @return string the Identifier of the new file
	 * @throws \RuntimeException
	 */
	public function copyFileWithinStorage($sourceFileIdentifier, $targetFolderIdentifier, $fileName) {

		$temporaryFile = $this->getTemporaryPathForFile($sourceFileIdentifier);
		$newFileIdentifier = $targetFolderIdentifier . $fileName;

		$result = @ftp_get($this->stream, $temporaryFile, $this->getAbsolutePath($sourceFileIdentifier), FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Open file "' . $sourceFileIdentifier . ' for copy faild".', 1407049686);
		}

		$result = @ftp_put($this->stream, $this->getAbsolutePath($newFileIdentifier), $temporaryFile, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Uploading file "' . $newFileIdentifier . ' for copy faild".', 1407049687);
		}

		return $newFileIdentifier;
	}

	/**
	 * Checks if a folder exists.
	 *
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function folderExists($folderIdentifier) {
		return $this->changeDirectory($folderIdentifier);
	}

	/**
	 * Checks if a folder inside a folder exists.
	 *
	 * @param string $folderName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, $folderIdentifier) {
		return $this->folderExists($folderIdentifier . $folderName);

	}

	/**
	 * Checks if a given identifier is within a container, e.g. if
	 * a file or folder is within another folder. It will also return
	 * TRUE if both canonicalized identifiers are equal.
	 *
	 * @param string $folderIdentifier
	 * @param string $identifier identifier to be checked against $folderIdentifier
	 * @return boolean TRUE if $content is within or matches $folderIdentifier
	 */
	public function isWithin($folderIdentifier, $identifier) {
		$folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
		$entryIdentifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
		if ($folderIdentifier === $entryIdentifier) {
			return TRUE;
		}
		return \TYPO3\CMS\Core\Utility\GeneralUtility::isFirstPartOfStr($entryIdentifier, $folderIdentifier);
	}

	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param string $folderIdentifier
	 * @return boolean TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty($folderIdentifier) {
		$this->fetchDirectory($folderIdentifier, TRUE);
		return (count($this->directoryCache[$folderIdentifier]) === 0);
	}

	/**
	 * Returns information about a folder.
	 *
	 * @param string $folderIdentifier In the case of the LocalDriver, this is the (relative) path to the file.
	 * @return array
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException
	 */
	public function getFolderInfoByIdentifier($folderIdentifier) {

		$folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
		if (!$this->folderExists($folderIdentifier)) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException('File ' . $folderIdentifier . ' does not exist.', 1314516810);
		}

		return array(
			'identifier' => $folderIdentifier,
			'name' => $this->getNameFromIdentifier($folderIdentifier),
			'storage' => $this->storageUid
		);
	}

	/**
	 * Returns a list of folders inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array $folderNameFilterCallbacks The method callbacks to use for filtering the items
	 *
	 * @return array of Folder Identifier
	 */
	public function getFoldersInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $folderNameFilterCallbacks = array()) {
		return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $folderNameFilterCallbacks, FALSE, TRUE, $recursive);
	}

	/**
	 * Creates a folder, within a parent folder.
	 * If no parent folder is given, a root level folder will be created
	 *
	 * @param string $newFolderName
	 * @param string $parentFolderIdentifier
	 * @param boolean $recursive
	 * @return string the Identifier of the new folder
	 * @throws \RuntimeException
	 */
	public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = FALSE) {
		$newFolderName = $this->sanitizeFileName($newFolderName);
		$folderIdentifier = $parentFolderIdentifier . $newFolderName . '/';
		$result = @ftp_mkdir($this->stream, $this->getAbsolutePath($folderIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Creating directory "' . $folderIdentifier . '" faild.', 1407049649);
		}
		$this->fetchDirectory($parentFolderIdentifier, TRUE);
		return $folderIdentifier;
	}

	/**
	 * Renames a folder in this storage.
	 *
	 * @param string $folderIdentifier
	 * @param string $newName
	 * @return array A map of old to new file identifiers of all affected resources
	 * @throws \RuntimeException if renaming the folder failed
	 */
	public function renameFolder($folderIdentifier, $newName) {

		$newName = $this->sanitizeFileName($newName);
		$newFolderIdentifier = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier) . $newName . '/';

		// Create a mapping from old to new identifiers
		$identifierMap = $this->createIdentifierMap($folderIdentifier, $newFolderIdentifier);

		$result = @ftp_rename($this->stream, $this->getAbsolutePath($folderIdentifier), $this->getAbsolutePath($newFolderIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Renaming folder ' . $folderIdentifier . ' to ' . $newFolderIdentifier . ' failed.', 1320375295);
		}

		return $identifierMap;
	}

	/**
	 * Removes a folder from this storage.
	 *
	 * @param string $folderIdentifier
	 * @param boolean $recursively
	 * @return boolean
	 * @throws \RuntimeException
	 */
	public function deleteFolder($folderIdentifier, $recursively = FALSE) {

		$this->fetchDirectory($folderIdentifier, TRUE);

		foreach ($this->directoryCache[$folderIdentifier] as $identifier => $fileInfo) {
			if ($fileInfo['isDirectory'] === FALSE) {
				$this->deleteFile($identifier);
			} else if ($recursively) {
				$this->deleteFolder($identifier, $recursively);
			}
		}

		// The ftp_rmdir may not work with all FTP servers. Solution: to delete /dir/parent/dirtodelete
		// 1. chdir to the parent directory  /dir/parent
		// 2. delete the subdirectory, but use only its name (dirtodelete), not the full path (/dir/parent/dirtodelete)
		$parentIdentifier = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier);
		@ftp_chdir($this->stream, $this->getAbsolutePath($parentIdentifier));

		$result = @ftp_rmdir($this->stream, trim($this->getNameFromIdentifier($folderIdentifier), '/'));
		if ($result === FALSE) {
			throw new \RuntimeException('Deleting file ' . $folderIdentifier . ' failed.', 1320381534);
		}

		return $result;
	}

	/**
	 * Folder equivalent to moveFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 * @return array All files which are affected, map of old => new file identifiers
	 * @throws \RuntimeException
	 */
	public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {

		// The target should not exist already.
		if ($this->folderExistsInFolder($newFolderName, $targetFolderIdentifier)) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The target folder already exists.', 1320291083);
		}

		$folderIdentifier = $targetFolderIdentifier . $newFolderName;

		// Create a mapping from old to new identifiers
		$identifierMap = $this->createIdentifierMap($sourceFolderIdentifier, $folderIdentifier);

		$result = @ftp_rename($this->stream, $this->getAbsolutePath($sourceFolderIdentifier), $this->getAbsolutePath($folderIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('Moveing folder ' . $sourceFolderIdentifier . ' to ' . $folderIdentifier . ' failed.', 1320375296);
		}

		return $identifierMap;
	}

	/**
	 * Folder equivalent to copyFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 * @return boolean
	 */
	public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {

		$this->fetchDirectory($sourceFolderIdentifier, TRUE);

		if ($this->folderExistsInFolder($newFolderName, $targetFolderIdentifier)) {
			// This exception is not shown in the backend...?
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The folder ' . $newFolderName . ' already exists in folder ' . $targetFolderIdentifier, 1325418870);
		}

		$newFolder = $this->createFolder($newFolderName, $targetFolderIdentifier);

		foreach ($this->directoryCache[$sourceFolderIdentifier] as $identifier => $fileInfo) {
			if ($fileInfo['isDirectory']) {
				$this->copyFolderWithinStorage(
					$sourceFolderIdentifier . $fileInfo['name'],
					$newFolder,
					$fileInfo['name']
				);
				$this->fetchDirectory($targetFolderIdentifier . $newFolderName, TRUE);
			} else {
				$this->copyFileWithinStorage(
					$sourceFolderIdentifier . $fileInfo['name'],
					$newFolder,
					$fileInfo['name']
				);
			}
		}

		return TRUE;
	}

	/**
	 * Move a folder from another storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $folderName
	 * @throws \BadMethodCallException
	 * @return boolean
	 */
	public function moveFolderBetweenStorages(\TYPO3\CMS\Core\Resource\Folder $folder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $folderName) {

		throw new \RuntimeException('Not yet implemented');

		if ($this->folderExistsInFolder($folderName, $targetFolder)) {
			// This exception is not shown in the backend...?
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The folder ' . $folderName . ' already exists in folder ' . $targetFolder->getIdentifier(), 1325418870);
		}

		$targetIdentifier = $targetFolder->getIdentifier() . $folderName . '/';
		$newTargetFolder = $this->createFolder($folderName, $targetFolder);

		$folderList = $folder->getStorage()->getFolderList($folder->getIdentifier());
		foreach ($folderList as $folderInfo) {
			$this->copyFolderBetweenStorages($folder->getStorage()->getFolder($folderInfo['identifier']), $newTargetFolder, $folderInfo['name']);
		}

		$files = $folder->getFiles($folder->getIdentifier());
		foreach ($files as $file) {
			$this->storage->moveFile($file, $newTargetFolder, $file->getName(), 'cancel');
		}

		$folder->delete();

		return TRUE;
	}

	/**
	 * Copy a folder from another storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $folderName
	 * @throws \BadMethodCallException
	 * @return boolean
	 */
	public function copyFolderBetweenStorages(\TYPO3\CMS\Core\Resource\Folder $folder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $folderName) {

		throw new \RuntimeException('Not yet implemented');

		if ($this->folderExistsInFolder($folderName, $targetFolder)) {
			// This exception is not shown in the backend...?
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The folder ' . $folderName . ' already exists in folder ' . $targetFolder->getIdentifier(), 1325418870);
		}

		$targetIdentifier = $targetFolder->getIdentifier() . $folderName . '/';
		$newTargetFolder = $this->createFolder($folderName, $targetFolder);

		$folderList = $folder->getStorage()->getFolderList($folder->getIdentifier());
		foreach ($folderList as $folderInfo) {
			$this->copyFolderBetweenStorages($folder->getStorage()->getFolder($folderInfo['identifier']), $newTargetFolder, $folderInfo['name']);
		}

		$files = $folder->getFiles($folder->getIdentifier());
		foreach ($files as $file) {
			$this->storage->copyFile($file, $newTargetFolder, $file->getName(), 'cancel');
		}

		return TRUE;
	}


	/**
	 * Returns the permissions of a file/folder as an array (keys r, w) of boolean flags
	 *
	 * @param string $identifier
	 * @return array
	 * @throws \RuntimeException
	 */
	public function getPermissions($identifier) {
		// TODO
		return array(
			'r' => TRUE,
			'w' => TRUE
		);
	}

	/**
	 * Creates a hash for a file.
	 *
	 * @param string $fileIdentifier
	 * @param string $hashAlgorithm The hash algorithm to use
	 * @return string
	 * @throws \InvalidArgumentException
	 * @throws \RuntimeException
	 */
	public function hash($fileIdentifier, $hashAlgorithm) {

		if (!in_array($hashAlgorithm, $this->supportedHashAlgorithms)) {
			throw new \InvalidArgumentException('Hash algorithm "' . $hashAlgorithm . '" is not supported.', 1304964032);
		}

		$folderIdentifier = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
		if (isset($this->directoryCache[$folderIdentifier][$fileIdentifier][$hashAlgorithm])) {
			return $this->directoryCache[$folderIdentifier][$fileIdentifier][$hashAlgorithm];
		}

		$temporaryFile = $this->getFileForLocalProcessing($fileIdentifier);

		switch ($hashAlgorithm) {
			case 'sha1':
				$hash = sha1_file($temporaryFile);
				break;
			case 'md5':
				$hash = md5_file($temporaryFile);
				break;
			default:
				throw new \RuntimeException('Hash algorithm ' . $hashAlgorithm . ' is not implemented.', 1329644451);
		}

		$this->directoryCache[$folderIdentifier][$fileIdentifier][$hashAlgorithm] = $hash;

		return $hash;
	}

	/**
	 * Basic implementation of the method that does directly return the
	 * file name as is.
	 *
	 * @param string $fileName Input string, typically the body of a fileName
	 * @param string $charset Charset of the a fileName (defaults to current charset; depending on context)
	 * @return string Output string with any characters not matching [.a-zA-Z0-9_-] is substituted by '_' and trailing dots removed
	 */
	public function sanitizeFileName($fileName, $charset = '') {
		return \TYPO3\CMS\Core\Utility\File\BasicFileUtility::cleanFileName($fileName);;
	}

	/**
	 * Generic wrapper for extracting a list of items from a path.
	 *
	 * @param string $folderIdentifier
	 * @param integer $start The position to start the listing; if not set, start from the beginning
	 * @param integer $numberOfItems The number of items to list; if set to zero, all items are returned
	 * @param array $filterMethods The filter methods used to filter the directory items
	 * @param boolean $includeFiles
	 * @param boolean $includeDirs
	 * @param boolean $recursive
	 *
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function getDirectoryItemList($folderIdentifier, $start = 0, $numberOfItems = 0, array $filterMethods, $includeFiles = TRUE, $includeDirs = TRUE, $recursive = FALSE) {

		if ($this->folderExists($folderIdentifier) === FALSE) {
			throw new \InvalidArgumentException('Cannot list items in directory ' . $folderIdentifier . ' - does not exist or is no directory', 1314349666);
		}

		$this->fetchDirectory($folderIdentifier);

		if ($start > 0) {
			$start--;
		}

		$iterator = new \ArrayIterator($this->directoryCache[$folderIdentifier]);
		if ($iterator->count() === 0) {
			return array();
		}
		$iterator->seek($start);

		// $c is the counter for how many items we still have to fetch (-1 is unlimited)
		$c = $numberOfItems > 0 ? $numberOfItems : - 1;
		$items = array();
		while ($iterator->valid() && ($numberOfItems === 0 || $c > 0)) {

			$iteratorItem = $iterator->current();
			$identifier = $iterator->key();

			// go on to the next iterator item now as we might skip this one early
			$iterator->next();

			if ($includeDirs === FALSE && $iteratorItem['isDirectory'] || $includeFiles === FALSE && $iteratorItem['isDirectory'] === FALSE) {
				continue;
			}

			if ($this->applyFilterMethodsToDirectoryItem($filterMethods, $iteratorItem['name'], $identifier, $this->getParentFolderIdentifierOfIdentifier($identifier)) === FALSE) {
				continue;
			}

			$items[$identifier] = $identifier;
			// Decrement item counter to make sure we only return $numberOfItems
			// we cannot do this earlier in the method (unlike moving the iterator forward) because we only add the
			// item here
			--$c;
		}

		return $items;
	}

	/**
	 * Applies a set of filter methods to a file name to find out if it should be used or not. This is e.g. used by
	 * directory listings.
	 *
	 * @param array $filterMethods The filter methods to use
	 * @param string $itemName
	 * @param string $itemIdentifier
	 * @param string $parentIdentifier
	 * @throws \RuntimeException
	 * @return boolean
	 */
	protected function applyFilterMethodsToDirectoryItem(array $filterMethods, $itemName, $itemIdentifier, $parentIdentifier) {
		foreach ($filterMethods as $filter) {
			if (is_array($filter)) {
				$result = call_user_func($filter, $itemName, $itemIdentifier, $parentIdentifier, array(), $this);
				// We have to use -1 as the â€ždon't includeâ€œ return value, as call_user_func() will return FALSE
				// If calling the method succeeded and thus we can't use that as a return value.
				if ($result === -1) {
					return FALSE;
				} elseif ($result === FALSE) {
					throw new \RuntimeException('Could not apply file/folder name filter ' . $filter[0] . '::' . $filter[1]);
				}
			}
		}
		return TRUE;
	}

	/**
	 * This function scans an ftp_rawlist line string and returns its parts (directory/file, name, size,...) using preg_match()
	 * Adapted from https://www.net2ftp.com
	 * Copyright (c) 2003-2013 by David Gartner
	 *
	 * @param string $folderIdentifier
	 * @param boolean $resetCache
	 * @return void
	 */
	protected function fetchDirectory($folderIdentifier, $resetCache = FALSE) {

		if ($resetCache === FALSE && is_array($this->directoryCache[$folderIdentifier])) {
			return $this->directoryCache[$folderIdentifier];
		}

		$this->directoryCache[$folderIdentifier] = array();
		$list = $this->getRawList($folderIdentifier);
		foreach ($list as &$line) {
			$this->parseResultLine($folderIdentifier, $line);
		}

		uksort($this->directoryCache[$folderIdentifier], 'strnatcasecmp');
	}

	/**
	 * This function scans an ftp_rawlist line string and returns its parts (name, size,...).
	 * Adapted from net2ftp by David Gartner
	 * @see https://www.net2ftp.com
	 *
	 * @param string $folderIdentifier
	 * @param array $line
	 * @return void
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException
	 */
	protected function parseResultLine($folderIdentifier, $line) {

		$fileInfo = array(
			'isDirectory' => NULL,
			'name' => NULL,
			'size' => NULL,
			'owner' => NULL,
			'group' => NULL,
			'mode' => NULL,
			'ctime' => 0,
			'mtime' => 0,
			'atime' => 0,
		);

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine'])) {

			ksort($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine']);
			foreach($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine'] as $hookFunction) {
				$hookResult = \TYPO3\CMS\Core\Utility\GeneralUtility::callUserFunction($hookFunction, $fileInfo, $line, $this);
				if ($hookResult) {
					break;
				}
			}

			// If nothing match throw exception.
			if ($hookResult === FALSE) {
				throw new \TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException('FTP outputformat not supported', 1407049745);
			}
		}

		// Name must be set.
		if ($fileInfo['name'] == FALSE) {
			return FALSE;
		}

		// Remove the . and .. entries
		if ($fileInfo['name'] == '.' || $fileInfo['name'] == '..') {
			return FALSE;
		}

		// Remove the total line that some servers return
		if (substr($line, 0, 5) == 'total') {
			return FALSE;
		}

		if ($fileInfo['isDirectory']) {
			$identifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier . $fileInfo['name']);
		} else {
			$identifier = $this->canonicalizeAndCheckFileIdentifier($folderIdentifier . $fileInfo['name']);
		}

		$fileInfo['storage'] = $this->storageUid;
		$fileInfo['parseRule'] = $hookFunction;

		if ($fileInfo['isDirectory'] === FALSE) {
			$fileInfo['mimetype'] = $this->getMimeType($fileInfo['name']);
		}

		$fileInfo['identifier'] = $identifier;
		$fileInfo['identifier_hash'] = $this->hashIdentifier($identifier);
		$fileInfo['folder_hash'] = $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($identifier));

		$this->directoryCache[$folderIdentifier][$identifier] = $fileInfo;
	}

	/**
	 * Changes the current directory on a FTP server.
	 *
	 * @param string $identifier Directory or file identifier.
	 * @return boolean
	 * @throws \RuntimeException
	 */
	protected function changeDirectory($identifier = '') {
		$result = @ftp_chdir($this->stream, $this->getAbsolutePath($identifier));
		return $result;
	}

	/**
	 * Changes the current directory on a FTP server.
	 *
	 * @param string $folderIdentifier
	 * @return array
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException
	 * @throws \RuntimeException
	 */
	protected function getRawList($folderIdentifier, $fileName = '') {

		$this->changeDirectory($folderIdentifier);

		// The -a option is used to show the hidden files as well on some FTP servers.
		$result = @ftp_rawlist($this->stream, '-a ' . $fileName);
		if ($result === FALSE) {
			throw new \RuntimeException('Fetching directory list of "' . $folderIdentifier . '" faild.', 1407049747);
		}

		// Some servers do not return anything when using -a, so in that case try again without the -a option.
		if (sizeof($result) <= 1) {
			$result = @ftp_rawlist($this->stream, $fileName);
			if ($result === FALSE) {
				throw new \RuntimeException('Fetching directory list of "' . $folderIdentifier . '" faild.', 1407049747);
			}
		}

		return $result;
	}

	/**
	 * Returns the mime type of given file extension.
	 *
	 * @param string $fileName
	 * @return string
	 */
	protected function getMimeType($fileName) {

		$extension = pathinfo($fileName, PATHINFO_EXTENSION);

		switch ($extension) {
			case 'ai':
			case 'eps':
			case 'ps':
				$mimeType = 'application/postscript'; break;
			case 'aif':
			case 'aifc':
			case 'aiff':
				$mimeType = 'audio/x-aiff'; break;
			case 'asc':
			case 'txt':
				$mimeType = 'text/plain'; break;
			case 'atom':
				$mimeType = 'application/atom+xml'; break;
			case 'au':
			case 'snd':
				$mimeType = 'audio/basic'; break;
			case 'avi':
				$mimeType = 'video/x-msvideo'; break;
			case 'bcpio':
				$mimeType = 'application/x-bcpio'; break;
			case 'bin':
			case 'class':
			case 'dll':
			case 'dmg':
			case 'dms':
			case 'exe':
			case 'lha':
			case 'lzh':
			case 'so':
				$mimeType = 'application/octet-stream'; break;
			case 'bmp':
				$mimeType = 'image/bmp'; break;
			case 'cdf':
			case 'nc':
				$mimeType = 'application/x-netcdf'; break;
			case 'cgm':
				$mimeType = 'image/cgm'; break;
			case 'cpio':
				$mimeType = 'application/x-cpio'; break;
			case 'cpt':
				$mimeType = 'application/mac-compactpro'; break;
			case 'csh':
				$mimeType = 'application/x-csh'; break;
			case 'css':
				$mimeType = 'text/css'; break;
			case 'dcr':
			case 'dir':
			case 'dxr':
				$mimeType = 'application/x-director'; break;
			case 'dif':
			case 'dv':
				$mimeType = 'video/x-dv'; break;
			case 'djv':
			case 'djvu':
				$mimeType = 'image/vnd.djvu'; break;
			case 'doc':
				$mimeType = 'application/msword'; break;
			case 'dtd':
				$mimeType = 'application/xml-dtd'; break;
			case 'dvi':
				$mimeType = 'application/x-dvi'; break;
			case 'etx':
				$mimeType = 'text/x-setext'; break;
			case 'ez':
				$mimeType = 'application/andrew-inset'; break;
			case 'gif':
				$mimeType = 'image/gif'; break;
			case 'gram':
				$mimeType = 'application/srgs'; break;
			case 'grxml':
				$mimeType = 'application/srgs+xml'; break;
			case 'gtar':
				$mimeType = 'application/x-gtar'; break;
			case 'hdf':
				$mimeType = 'application/x-hdf'; break;
			case 'hqx':
				$mimeType = 'application/mac-binhex40'; break;
			case 'htm':
			case 'html':
				$mimeType = 'text/html'; break;
			case 'ice':
				$mimeType = 'x-conference/x-cooltalk'; break;
			case 'ico':
				$mimeType = 'image/x-icon'; break;
			case 'ics':
			case 'ifb':
				$mimeType = 'text/calendar'; break;
			case 'ief':
				$mimeType = 'image/ief'; break;
			case 'iges':
			case 'igs':
				$mimeType = 'model/iges'; break;
			case 'jnlp':
				$mimeType = 'application/x-java-jnlp-file'; break;
			case 'jp2':
				$mimeType = 'image/jp2'; break;
			case 'jpe':
			case 'jpeg':
			case 'jpg':
				$mimeType = 'image/jpeg'; break;
			case 'js':
				$mimeType = 'application/x-javascript'; break;
			case 'kar':
			case 'mid':
			case 'midi':
				$mimeType = 'audio/midi'; break;
			case 'latex':
				$mimeType = 'application/x-latex'; break;
			case 'm3u':
				$mimeType = 'audio/x-mpegurl'; break;
			case 'm4a':
			case 'm4b':
			case 'm4p':
				$mimeType = 'audio/mp4a-latm'; break;
			case 'm4u':
			case 'mxu':
				$mimeType = 'video/vnd.mpegurl'; break;
			case 'm4v':
				$mimeType = 'video/x-m4v'; break;
			case 'mac':
			case 'pnt':
			case 'pntg':
				$mimeType = 'image/x-macpaint'; break;
			case 'man':
				$mimeType = 'application/x-troff-man'; break;
			case 'mathml':
				$mimeType = 'application/mathml+xml'; break;
			case 'me':
				$mimeType = 'application/x-troff-me'; break;
			case 'mesh':
			case 'msh':
			case 'silo':
				$mimeType = 'model/mesh'; break;
			case 'mif':
				$mimeType = 'application/vnd.mif'; break;
			case 'mov':
			case 'qt':
				$mimeType = 'video/quicktime'; break;
			case 'movie':
				$mimeType = 'video/x-sgi-movie'; break;
			case 'mp2':
			case 'mp3':
			case 'mpga':
				$mimeType = 'audio/mpeg'; break;
			case 'mp4':
				$mimeType = 'video/mp4'; break;
			case 'mpe':
			case 'mpeg':
			case 'mpg':
				$mimeType = 'video/mpeg'; break;
			case 'ms':
				$mimeType = 'application/x-troff-ms'; break;
			case 'oda':
				$mimeType = 'application/oda'; break;
			case 'ogg':
				$mimeType = 'application/ogg'; break;
			case 'pbm':
				$mimeType = 'image/x-portable-bitmap'; break;
			case 'pct':
			case 'pic':
			case 'pict':
				$mimeType = 'image/pict'; break;
			case 'pdb':
				$mimeType = 'chemical/x-pdb'; break;
			case 'pdf':
				$mimeType = 'application/pdf'; break;
			case 'pgm':
				$mimeType = 'image/x-portable-graymap'; break;
			case 'pgn':
				$mimeType = 'application/x-chess-pgn'; break;
			case 'png':
				$mimeType = 'image/png'; break;
			case 'pnm':
				$mimeType = 'image/x-portable-anymap'; break;
			case 'ppm':
				$mimeType = 'image/x-portable-pixmap'; break;
			case 'ppt':
				$mimeType = 'application/vnd.ms-powerpoint'; break;
			case 'qti':
			case 'qtif':
				$mimeType = 'image/x-quicktime'; break;
			case 'ra':
			case 'ram':
				$mimeType = 'audio/x-pn-realaudio'; break;
			case 'ras':
				$mimeType = 'image/x-cmu-raster'; break;
			case 'rdf':
				$mimeType = 'application/rdf+xml'; break;
			case 'rgb':
				$mimeType = 'image/x-rgb'; break;
			case 'rm':
				$mimeType = 'application/vnd.rn-realmedia'; break;
			case 'roff':
			case 't':
			case 'tr':
				$mimeType = 'application/x-troff'; break;
			case 'rtf':
				$mimeType = 'text/rtf'; break;
			case 'rtx':
				$mimeType = 'text/richtext'; break;
			case 'sgm':
			case 'sgml':
				$mimeType = 'text/sgml'; break;
			case 'sh':
				$mimeType = 'application/x-sh'; break;
			case 'shar':
				$mimeType = 'application/x-shar'; break;
			case 'sit':
				$mimeType = 'application/x-stuffit'; break;
			case 'skd':
			case 'skm':
			case 'skp':
			case 'skt':
				$mimeType = 'application/x-koan'; break;
			case 'smi':
			case 'smil':
				$mimeType = 'application/smil'; break;
			case 'spl':
				$mimeType = 'application/x-futuresplash'; break;
			case 'src':
				$mimeType = 'application/x-wais-source'; break;
			case 'sv4cpio':
				$mimeType = 'application/x-sv4cpio'; break;
			case 'sv4crc':
				$mimeType = 'application/x-sv4crc'; break;
			case 'svg':
				$mimeType = 'image/svg+xml'; break;
			case 'swf':
				$mimeType = 'application/x-shockwave-flash'; break;
			case 'tar':
				$mimeType = 'application/x-tar'; break;
			case 'tcl':
				$mimeType = 'application/x-tcl'; break;
			case 'tex':
				$mimeType = 'application/x-tex'; break;
			case 'texi':
			case 'texinfo':
				$mimeType = 'application/x-texinfo'; break;
			case 'tif':
			case 'tiff':
				$mimeType = 'image/tiff'; break;
			case 'tsv':
				$mimeType = 'text/tab-separated-values'; break;
			case 'ustar':
				$mimeType = 'application/x-ustar'; break;
			case 'vcd':
				$mimeType = 'application/x-cdlink'; break;
			case 'vrml':
			case 'wrl':
				$mimeType = 'model/vrml'; break;
			case 'vxml':
				$mimeType = 'application/voicexml+xml'; break;
			case 'wav':
				$mimeType = 'audio/x-wav'; break;
			case 'wbmp':
				$mimeType = 'image/vnd.wap.wbmp'; break;
			case 'wbmxl':
				$mimeType = 'application/vnd.wap.wbxml'; break;
			case 'wml':
				$mimeType = 'text/vnd.wap.wml'; break;
			case 'wmlc':
				$mimeType = 'application/vnd.wap.wmlc'; break;
			case 'wmls':
				$mimeType = 'text/vnd.wap.wmlscript'; break;
			case 'wmlsc':
				$mimeType = 'application/vnd.wap.wmlscriptc'; break;
			case 'xbm':
				$mimeType = 'image/x-xbitmap'; break;
			case 'xht':
			case 'xhtml':
				$mimeType = 'application/xhtml+xml'; break;
			case 'xls':
				$mimeType = 'application/vnd.ms-excel'; break;
			case 'xml':
			case 'xsl':
				$mimeType = 'application/xml'; break;
			case 'xpm':
				$mimeType = 'image/x-xpixmap'; break;
			case 'xslt':
				$mimeType = 'application/xslt+xml'; break;
			case 'xul':
				$mimeType = 'application/vnd.mozilla.xul+xml'; break;
			case 'xwd':
				$mimeType = 'image/x-xwindowdump'; break;
			case 'xyz':
				$mimeType = 'chemical/x-xyz'; break;
			case 'zip':
				$mimeType = 'application/zip'; break;
			default:
				$mimeType = 'application/octet-stream'; break;
		}

		return $mimeType;
	}

	/**
	 * Creates a map of old and new file/folder identifiers after renaming or
	 * moving a folder. The old identifier is used as the key, the new one as the value.
	 *
	 * @param string $oldIdentifier
	 * @param string $newIdentifier
	 * @param array $identifierMap
	 * @return array
	 * @throws \RuntimeException
	 */
	protected function createIdentifierMap($oldIdentifier, $newIdentifier, &$identifierMap = array()) {

		// If change direcory fails, then it must be a file.
		if ($this->changeDirectory($oldIdentifier) === FALSE) {
			$identifierMap[$oldIdentifier] = $newIdentifier;
			return $identifierMap;
		}

		// If is a directory, make valid identifier.
		$oldIdentifier = rtrim($oldIdentifier, '/') . '/';
		$newIdentifier = rtrim($newIdentifier, '/') . '/';
		$identifierMap[$oldIdentifier] = $newIdentifier;

		$list = @ftp_nlist($this->stream, '.');
		if ($list === FALSE) {
			throw new \RuntimeException('Fetching list of directory "' . $oldIdentifier . '" faild.', 1407049647);
		}
		foreach ($list as &$identifier) {
			if ($identifier == '.' || $identifier == '..' || substr($identifier, 0, 5) == 'total') {
				continue;
			}
			$this->createIdentifierMap($oldIdentifier . $identifier, $newIdentifier . $identifier, $identifierMap);
		}

		return $identifierMap;
	}

	/**
	 * Returns the absolute path of the FTP remote directory or file.
	 *
	 * @param string $identifier
	 * @return array
	 */
	protected function getAbsolutePath($identifier) {
		return $this->basePath . '/' . ltrim($identifier, '/');
	}

	/**
	 * Returns the cache identifier for a given path.
	 *
	 * @param string $identifier
	 * @return string
	 */
	protected function getCacheKey($identifier) {
		return sha1($this->storageUid . ':' . $identifier);
	}

	/**
	 * Returns the cache identifier for a given path.
	 *
	 * @param string $identifier
	 * @return string
	 */
	protected function getNameFromIdentifier($identifier) {
		return trim(\TYPO3\CMS\Core\Utility\PathUtility::basename($identifier), '/');
	}

	/**
	 * Add flash message to message queue.
	 *
	 * @param string $message
	 * @param integer $severity
	 * @return void
	 */
	protected function addFlashMessage($message, $severity = \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR) {
		$flashMessage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
			'TYPO3\\CMS\\Core\\Messaging\\FlashMessage',
			$message,
			'',
			$severity,
			TRUE
		);
		/** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
		$flashMessageService = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessageService');
		/** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
		$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
		$defaultFlashMessageQueue->enqueue($flashMessage);
	}
}


?>