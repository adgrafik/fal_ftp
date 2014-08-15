<?php
namespace AdGrafik\FalFtp;

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

use TYPO3\CMS\Core\Utility\PathUtility;

class FTPDriver extends \TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver {

	/**
	 * @var string
	 */
	const UNSAFE_FILENAME_CHARACTER_EXPRESSION = '\\x00-\\x2C\\/\\x3A-\\x3F\\x5B-\\x60\\x7B-\\xBF';

	/**
	 * The storage uid the driver was instantiated for.
	 * Backports from TYPO3 v6.2.x.
	 *
	 * @var integer
	 */
	protected $storageUid;

	/**
	 * A list of all supported hash algorithms, written all lower case and
	 * without any dashes etc. (e.g. sha1 instead of SHA-1)
	 * Be sure to set this in inherited classes!
	 *
	 * @var array
	 */
	protected $supportedHashAlgorithms = array('sha1', 'md5');

	/**
	 * The $directoryCache caches all files including file info which are loaded via FTP.
	 * This cache get refreshed only when an user action is done or file is processed.
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
	 * Initializes this object. This is called by the storage after the driver
	 * has been attached.
	 *
	 * @return void
	 */
	public function initialize() {
		// The capabilities default of this driver. See CAPABILITY_* constants for possible values
		$this->capabilities =
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_BROWSABLE |
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_PUBLIC |
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_WRITABLE;
		// Backports $storageUid from TYPO3 v6.2.x.
		$this->storageUid = $this->storage->getUid();
		$this->directoryCache = array();
	}

	/**
	 * processes the configuration, should be overridden by subclasses
	 *
	 * @return void
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
	 *
	 * @param \TYPO3\CMS\Core\Resource\ResourceInterface $resource
	 * @param bool  $relativeToCurrentScript    Determines whether the URL returned should be relative to the current script, in case it is relative at all (only for the LocalDriver)
	 * @return string
	 */
	public function getPublicUrl(\TYPO3\CMS\Core\Resource\ResourceInterface $resource, $relativeToCurrentScript = FALSE) {
		return $this->publicUrl . $resource->getIdentifier();
	}

	/**
	 * Returns the root level folder of the storage.
	 *
	 * @return \TYPO3\CMS\Core\Resource\Folder
	 */
	public function getRootLevelFolder() {
		return $this->getFolder('/');
	}

	/**
	 * Returns the default folder new files should be put into.
	 *
	 * @return \TYPO3\CMS\Core\Resource\Folder
	 */
	public function getDefaultFolder() {
		if ($this->folderExists('/user_upload/') === FALSE) {
			$parentFolder = $this->getFoder('/');
			return $this->createFolder('user_upload', $parentFolder);
		} else {
			return $this->getFoder('/user_upload/');
		}
	}

	/**
	 * Checks if a file exists.
	 *
	 * @param string $fileIdentifier
	 * @return boolean
	 */
	public function fileExists($fileIdentifier) {
		$result = @ftp_size($this->stream, $this->getAbsolutePath($fileIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Fetching size of file "' . $fileIdentifier . '" faild.', 1407049650);
		}
		return ($result !== -1);

	}

	/**
	 * Checks if a file inside a storage folder exists.
	 *
	 * @param string $fileName
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @return boolean
	 */
	public function fileExistsInFolder($fileName, \TYPO3\CMS\Core\Resource\Folder $folder) {
		return $this->fileExists($folder->getIdentifier() . $fileName);
	}

	/**
	 * Returns information about a file for a given file identifier.
	 *
	 * @param string $identifier The (relative) path to the file.
	 * @return array
	 */
	public function getFileInfoByIdentifier($identifier) {
		$folderIdentifier = $this->getParentFolderIdentifierOfIdentifier($identifier);
		if (isset($this->directoryCache[$folderIdentifier][$identifier]) === FALSE) {
			// If not found try to load again.
			$fileName = $this->getNameFromIdentifier($identifier);
			$this->fetchDirectory($folderIdentifier, TRUE);
			if (isset($this->directoryCache[$folderIdentifier][$identifier]) === FALSE) {
				$this->directoryCache[$folderIdentifier][$identifier] = array();
			}
		}
		return $this->directoryCache[$folderIdentifier][$identifier];
	}

	/**
	 * Returns the permissions of a file as an array (keys r, w) of boolean flags
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return array
	 */
	public function getFilePermissions(\TYPO3\CMS\Core\Resource\FileInterface $file) {
		// TODO
		return array(
			'r' => TRUE,
			'w' => TRUE,
		);
	}

	/**
	 * Adds a file from the local server hard disk to a given path in TYPO3s virtual file system.
	 *
	 * This assumes that the local file exists, so no further check is done here!
	 *
	 * @param string $sourceIdentifier
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $newFileName The name to add the file under
	 * @param \TYPO3\CMS\Core\Resource\AbstractFile $updateFileObject Optional file object to update (instead of creating a new object). With this parameter, this function can be used to "populate" a dummy file object with a real file underneath.
	 * @return \TYPO3\CMS\Core\Resource\FileInterface
	 */
	public function addFile($sourceIdentifier, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFileName, \TYPO3\CMS\Core\Resource\AbstractFile $updateFileObject = NULL) {

		$newFileIdentifier = $this->addFileRaw($sourceIdentifier, $targetFolder, $newFileName);
		$targetFolderIdentifier = $targetFolder->getIdentifier();

		if ($updateFileObject) {
			$updateFileObject->updateProperties($this->directoryCache[$targetFolderIdentifier][$newFileIdentifier]);
			return $updateFileObject;
		} else {
			$fileObject = $this->getFileObject($this->directoryCache[$targetFolderIdentifier][$newFileIdentifier]);
			return $fileObject;
		}

		return $this->getFile($newFileIdentifier);
	}

	/**
	 * Adds a file at the specified location. This should only be used internally.
	 *
	 * @param string $sourceIdentifier
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $newFileName
	 * @return string The new identifier of the file
	 */
	// TODO check if this is still necessary if we move more logic to the storage
	public function addFileRaw($sourceIdentifier, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFileName) {

		$targetFolderIdentifier = $targetFolder->getIdentifier();
		$newFileName = $this->sanitizeFileName($newFileName);
		$newFileIdentifier = $targetFolderIdentifier . $newFileName;

		$result = @ftp_put($this->stream, $this->getAbsolutePath($newFileIdentifier), $sourceIdentifier, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Unable to upload file "' . $newFileIdentifier . '".', 1407049655);
		}

		$this->fetchDirectory($targetFolderIdentifier, TRUE);

		return $newFileIdentifier;
	}

	/**
	 * Creates a new file and returns the matching file object for it.
	 *
	 * @param string $fileName
	 * @param \TYPO3\CMS\Core\Resource\Folder $parentFolder
	 * @return \TYPO3\CMS\Core\Resource\File
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException
	 * @throws \RuntimeException
	 */
	public function createFile($fileName, \TYPO3\CMS\Core\Resource\Folder $parentFolder) {

		if ($this->isValidFilename($fileName) === FALSE) {
			throw new \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException('Invalid characters in fileName "' . $fileName . '"', 1320572272);
		}

		$fileName = $this->sanitizeFileName($fileName);
		$fileIdentifier = $parentFolder->getIdentifier() . $fileName;
		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);

		$result = @ftp_put($this->stream, $this->getAbsolutePath($fileIdentifier), $temporaryFile, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Creating file ' . $fileIdentifier . ' failed.', 1320569854);
		}

		$fileInfo = $this->getFileInfoByIdentifier($fileIdentifier);
		return $this->getFileObject($fileInfo);
	}

	/**
	 * Renames a file
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @param string $newName
	 * @return string The new identifier of the file if the operation succeeds
	 * @throws \RuntimeException if renaming the file failed
	 */
	public function renameFile(\TYPO3\CMS\Core\Resource\FileInterface $file, $newName) {

		$fileIdentifier = $file->getIdentifier();
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
	 * @param \TYPO3\CMS\Core\Resource\AbstractFile $file
	 * @param string $localFilePath
	 * @return boolean
	 * @throws \RuntimeException
	 */
	public function replaceFile(\TYPO3\CMS\Core\Resource\AbstractFile $file, $localFilePath) {

		$fileIdentifier = $file->getIdentifier();

		$result = @ftp_put($this->stream, $this->getAbsolutePath($fileIdentifier), $localFilePath, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('Unable to upload file "' . $fileIdentifier . '".', 1407049655);
		}

		return $result;
	}

	/**
	 * Removes a file from this storage. This does not check if the file is
	 * still used or if it is a bad idea to delete it for some other reason
	 * this has to be taken care of in the upper layers (e.g. the Storage)!
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return boolean TRUE if deleting the file succeeded
	 */
	public function deleteFile(\TYPO3\CMS\Core\Resource\FileInterface $file) {
		$identifier = $file->getIdentifier();
		return $this->deleteFileRaw($identifier);
	}

	/**
	 * Deletes a file without access and usage checks.
	 * This should only be used internally.
	 *
	 * This accepts an identifier instead of an object because we might want to
	 * delete files that have no object associated with (or we don't want to
	 * create an object for) them - e.g. when moving a file to another storage.
	 *
	 * @param string $identifier
	 * @return boolean TRUE if removing the file succeeded
	 */
	public function deleteFileRaw($identifier) {
		$result = @ftp_delete($this->stream, $this->getAbsolutePath($identifier));
		return $result;
	}

	/**
	 * Sets the contents of a file to the specified value.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @param string $contents
	 * @return integer The number of bytes written to the file
	 * @throws \RuntimeException
	 */
	public function setFileContents(\TYPO3\CMS\Core\Resource\FileInterface $file, $contents) {
		$identifier = $file->getIdentifier();
		$temporaryFile = $this->getTemporaryPathForFile($identifier);
		$bytes = file_put_contents($temporaryFile, $contents);
		$result = @ftp_put($this->stream, $this->getAbsolutePath($identifier), $temporaryFile, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Unable to upload file "' . $identifier . '".', 1407049655);
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
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return string The file contents
	 * @throws \RuntimeException
	 */
	public function getFileContents(\TYPO3\CMS\Core\Resource\FileInterface $file) {
		$fileIdentifier = $file->getIdentifier();
		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);
		if (@ftp_get($this->stream, $temporaryFile, $this->getAbsolutePath($fileIdentifier), FTP_BINARY) === FALSE) {
			throw new \RuntimeException('Unable to read file "' . $fileIdentifier . '".', 1407049655);
		}
		$contents = file_get_contents($temporaryFile);
		unlink($temporaryFile);
		return $contents;
	}

	/**
	 * Returns a (local copy of) a file for processing it. When changing the
	 * file, you have to take care of replacing the current version yourself!
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @param bool $writable Set this to FALSE if you only need the file for read operations. This might speed up things, e.g. by using a cached local version. Never modify the file if you have set this flag!
	 * @return string The path to the file on the local disk
	 */
	// TODO decide if this should return a file handle object
	public function getFileForLocalProcessing(\TYPO3\CMS\Core\Resource\FileInterface $file, $writable = TRUE) {
		return $this->copyFileToTemporaryPath($file);
	}

	/**
	 * Copies a file to a temporary path and returns that path.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return string The temporary path
	 * @throws \RuntimeException
	 */
	public function copyFileToTemporaryPath(\TYPO3\CMS\Core\Resource\FileInterface $file) {
		// This function is called up to three times. So cache temporary file path to avoid too much loading.
		$identifier = $file->getIdentifier();
		$temporaryFile = $this->getTemporaryPathForFile($identifier);
		$result = @ftp_get($this->stream, $temporaryFile, $this->getAbsolutePath($identifier), FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Unable to read file "' . $identifier . '".', 1407049655);
		}
		return $temporaryFile;
	}

	/**
	 * Moves a file *within* the current storage.
	 * Note that this is only about an intra-storage move action, where a file is just
	 * moved to another folder in the same storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $sourceFile
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $newFileName
	 * @return string The new identifier of the file
	 * @throws \RuntimeException
	 */
	public function moveFileWithinStorage(\TYPO3\CMS\Core\Resource\FileInterface $sourceFile, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFileName) {

		$sourceFileIdentifier = $sourceFile->getIdentifier();
		$targetFileIdentifier = $targetFolder->getIdentifier() . $newFileName;

		// The target should not exist already.
		if ($this->fileExists($targetFileIdentifier)) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The target file already exists.', 1320291063);
		}

		$result = @ftp_rename($this->stream, $this->getAbsolutePath($sourceFileIdentifier), $this->getAbsolutePath($targetFileIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Moving file from ' . $sourceFileIdentifier . ' to ' . $targetFileIdentifier . ' failed.', 1320375195);
		}

		$this->fetchDirectory($this->getParentFolderIdentifierOfIdentifier($sourceFileIdentifier), TRUE);

		return $targetFileIdentifier;
	}

	/**
	 * Copies a file *within* the current storage.
	 * Note that this is only about an intra-storage copy action, where a file is just
	 * copied to another folder in the same storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $sourceFile
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $fileName
	 * @return \TYPO3\CMS\Core\Resource\FileInterface The new (copied) file object.
	 * @throws \RuntimeException
	 */
	public function copyFileWithinStorage(\TYPO3\CMS\Core\Resource\FileInterface $sourceFile, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $fileName) {

		$sourceFileIdentifier = $sourceFile->getIdentifier();
		$targetFileIdentifier = $targetFolder->getIdentifier() . $fileName;
		$temporaryFile = $this->getTemporaryPathForFile($sourceFileIdentifier);

		$result = @ftp_get($this->stream, $temporaryFile, $this->getAbsolutePath($sourceFileIdentifier), FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Open file "' . $sourceFileIdentifier . ' for copy faild".', 1407049686);
		}

		$result = @ftp_put($this->stream, $this->getAbsolutePath($targetFileIdentifier), $temporaryFile, FTP_BINARY);
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Uploading file "' . $targetFileIdentifier . ' for copy faild".', 1407049687);
		}

		return $this->getFile($targetFileIdentifier);
	}

	/**
	 * Checks if a folder exists
	 *
	 * @param string $identifier
	 * @return boolean
	 */
	public function folderExists($identifier) {
		return $this->changeDirectory($identifier);
	}

	/**
	 * Checks if a file inside a storage folder exists.
	 *
	 * @param string $folderName
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, \TYPO3\CMS\Core\Resource\Folder $folder) {
		return $this->folderExists($folder->getIdentifier() . $folderName);
	}

	/**
	 * Checks if a given object or identifier is within a container, e.g. if
	 * a file or folder is within another folder.
	 * This can e.g. be used to check for webmounts.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param mixed $content An object or an identifier to check
	 * @return boolean TRUE if $content is within $folder
	 */
	public function isWithin(\TYPO3\CMS\Core\Resource\Folder $folder, $content) {
		if ($folder->getStorage() != $this->storage) {
			return FALSE;
		}
		if ($content instanceof \TYPO3\CMS\Core\Resource\FileInterface || $content instanceof \TYPO3\CMS\Core\Resource\Folder) {
			$content = $folder->getIdentifier();
		}
		$folderIdentifier = $folder->getIdentifier();
		$content = '/' . ltrim($content, '/');

		return \TYPO3\CMS\Core\Utility\GeneralUtility::isFirstPartOfStr($content, $folderIdentifier);
	}

	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @return boolean TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty(\TYPO3\CMS\Core\Resource\Folder $folder) {
		$folderIdentifier = $folder->getIdentifier();
		$this->fetchDirectory($folderIdentifier, TRUE);
		return (count($this->directoryCache[$folderIdentifier]) === 0);
	}

	/**
	 * Returns a folder within the given folder. Use this method instead of doing your own string manipulation magic
	 * on the identifiers because non-hierarchical storages might fail otherwise.
	 *
	 * @param $folderName
	 * @param \TYPO3\CMS\Core\Resource\Folder $parentFolder
	 * @return \TYPO3\CMS\Core\Resource\Folder
	 */
	public function getFolderInFolder($folderName, \TYPO3\CMS\Core\Resource\Folder $parentFolder) {
		$identifier = $parentFolder->getIdentifier() . $folderName . '/';
		return $this->getFolder($identifier);
	}

	/**
	 * Returns the permissions of a folder as an array (keys r, w) of boolean flags
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @return array
	 */
	public function getFolderPermissions(\TYPO3\CMS\Core\Resource\Folder $folder) {
		// TODO
		return array(
			'r' => TRUE,
			'w' => TRUE,
		);
	}

	/**
	 * Creates a folder.
	 *
	 * @param string $folderName
	 * @param \TYPO3\CMS\Core\Resource\Folder $parentFolder
	 * @return \TYPO3\CMS\Core\Resource\Folder The new (created) folder object
	 * @throws \RuntimeException
	 */
	public function createFolder($folderName, \TYPO3\CMS\Core\Resource\Folder $parentFolder) {
		$folderName = \TYPO3\CMS\Core\Utility\File\BasicFileUtility::cleanFileName($folderName);
		$folderIdentifier = $parentFolder->getIdentifier() . $folderName . '/';
		$result = @ftp_mkdir($this->stream, $this->getAbsolutePath($folderIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Creating directory "' . $folderIdentifier . '" faild.', 1407049649);
		}
		$this->fetchDirectory($parentFolder->getIdentifier(), TRUE);
		return \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->createFolderObject($this->storage, $folderIdentifier, $folderName);
	}

	/**
	 * Renames a folder in this storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param string $newName The target path (including the file name!)
	 * @return array A map of old to new file identifiers
	 * @throws \RuntimeException if renaming the folder failed
	 */
	public function renameFolder(\TYPO3\CMS\Core\Resource\Folder $folder, $newName) {

		$folderIdentifier = $folder->getIdentifier();
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
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param boolean $recursively
	 * @return boolean
	 * @throws \RuntimeException
	 */
	public function deleteFolder(\TYPO3\CMS\Core\Resource\Folder $folder, $recursively = FALSE) {

		$folderIdentifier = $folder->getIdentifier();
		$this->fetchDirectory($folderIdentifier, TRUE);

		foreach ($this->directoryCache[$folderIdentifier] as $identifier => $fileInfo) {
			if ($fileInfo['isDirectory'] === FALSE) {
				$this->deleteFile($this->getFile($identifier));
			} else if ($recursively) {
				$this->deleteFolder($this->getFolder($identifier), $recursively);
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
	 * @param \TYPO3\CMS\Core\Resource\Folder $sourceFolder
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $newFolderName
	 * @return array A map of old to new file identifiers
	 * @throws \RuntimeException
	 */
	public function moveFolderWithinStorage(\TYPO3\CMS\Core\Resource\Folder $sourceFolder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFolderName) {

		// The target should not exist already.
		if ($this->folderExistsInFolder($newFolderName, $targetFolder)) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The target folder already exists.', 1320291083);
		}

		$oldIdentifier = $sourceFolder->getIdentifier();
		$newIdentifier = $this->canonicalizeAndCheckFolderPath($targetFolder->getIdentifier() . $newFolderName);

		// Create a mapping from old to new identifiers
		$identifierMap = $this->createIdentifierMap($oldIdentifier, $newIdentifier);

		$result = @ftp_rename($this->stream, $this->getAbsolutePath($oldIdentifier), $this->getAbsolutePath($newIdentifier));
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Moveing folder ' . $oldIdentifier . ' to ' . $newIdentifier . ' failed.', 1320375296);
		}

		return $identifierMap;
	}

	/**
	 * Folder equivalent to copyFileWithinStorage().
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $sourceFolder
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $folderName
	 * @return boolean
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 */
	public function copyFolderWithinStorage(\TYPO3\CMS\Core\Resource\Folder $sourceFolder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $folderName) {

		$sourceIdentifier = $sourceFolder->getIdentifier();
		$targetIdentifier = $targetFolder->getIdentifier();
		$this->fetchDirectory($sourceIdentifier, TRUE);

		if ($this->folderExistsInFolder($folderName, $targetFolder)) {
			// This exception is not shown in the backend...?
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The folder ' . $folderName . ' already exists in folder ' . $targetIdentifier, 1325418870);
		}
		$newFolder = $this->createFolder($folderName, $targetFolder);

		foreach ($this->directoryCache[$sourceIdentifier] as $identifier => $fileInfo) {
			if ($fileInfo['isDirectory']) {
				$this->copyFolderWithinStorage(
					$this->getFolder($sourceIdentifier . $fileInfo['name']),
					$newFolder,
					$fileInfo['name']
				);
				$this->fetchDirectory($targetIdentifier . $folderName, TRUE);
			} else {
				$this->copyFileWithinStorage(
					$this->getFile($sourceIdentifier . $fileInfo['name']),
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
	 * Checks if a resource exists - does not care for the type (file or folder).
	 *
	 * @param $identifier
	 * @return boolean
	 */
	public function resourceExists($identifier) {
		if ($this->folderExists($identifier) === FALSE) {
			return $this->fileExists($identifier);
		}
		return TRUE;
	}

	/**
	 * Creates a (cryptographic) hash for a file.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @param string $hashAlgorithm The hash algorithm to use
	 * @return string
	 * @throws \InvalidArgumentException
	 * @throws \RuntimeException
	 */
	public function hash(\TYPO3\CMS\Core\Resource\FileInterface $file, $hashAlgorithm) {

		if (!in_array($hashAlgorithm, $this->supportedHashAlgorithms)) {
			throw new \InvalidArgumentException('Hash algorithm "' . $hashAlgorithm . '" is not supported.', 1304964032);
		}

		$fileIdentifier = $file->getIdentifier();
		$folderIdentifier = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
		if (isset($this->directoryCache[$folderIdentifier][$fileIdentifier][$hashAlgorithm])) {
			return $this->directoryCache[$folderIdentifier][$fileIdentifier][$hashAlgorithm];
		}

		$temporaryFile = $this->getFileForLocalProcessing($file);

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
	 * Returns a string where any character not matching [.a-zA-Z0-9_-] is
	 * substituted by '_'
	 * Trailing dots are removed
	 *
	 * Previously in \TYPO3\CMS\Core\Utility\File\BasicFileUtility::cleanFileName()
	 *
	 * @param string $fileName Input string, typically the body of a fileName
	 * @param string $charset Charset of the a fileName (defaults to current charset; depending on context)
	 * @return string Output string with any characters not matching [.a-zA-Z0-9_-] is substituted by '_' and trailing dots removed
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException
	 */
	public function sanitizeFileName($fileName, $charset = '') {
		// Handle UTF-8 characters
		if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['UTF8filesystem']) {
			// Allow ".", "-", 0-9, a-z, A-Z and everything beyond U+C0 (latin capital letter a with grave)
			$cleanFileName = preg_replace('/[' . self::UNSAFE_FILENAME_CHARACTER_EXPRESSION . ']/u', '_', trim($fileName));
		} else {
			// Define character set
			if (!$charset) {
				if (TYPO3_MODE === 'FE') {
					$charset = $GLOBALS['TSFE']->renderCharset;
				} else {
					// default for Backend
					$charset = 'utf-8';
				}
			}
			// If a charset was found, convert fileName
			if ($charset) {
				$fileName = $this->getCharsetConversion()->specCharsToASCII($charset, $fileName);
			}
			// Replace unwanted characters by underscores
			$cleanFileName = preg_replace('/[' . self::UNSAFE_FILENAME_CHARACTER_EXPRESSION . '\\xC0-\\xFF]/', '_', trim($fileName));
		}
		// Strip trailing dots and return
		$cleanFileName = preg_replace('/\\.*$/', '', $cleanFileName);
		if (!$cleanFileName) {
			throw new \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException(
				'File name ' . $cleanFileName . ' is invalid.',
				1320288991
			);
		}
		return $cleanFileName;
	}

	/**
	 * Generic handler method for directory listings - gluing together the
	 * listing items is done
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param array $filterMethods The filter methods used to filter the directory items
	 * @param string $itemHandlerMethod
	 * @param boolean $recursive
	 * @return array
	 */
	protected function getDirectoryItemList($folderIdentifier, $start, $numberOfItems, array $filterMethods, $itemHandlerMethod, $itemRows = array(), $recursive = FALSE) {

		// Actualize processing folder.
		$this->fetchDirectory($folderIdentifier);
#		$this->fetchDirectory($this->storage->getProcessingFolder()->getIdentifier());

		$iterator = new \ArrayIterator($this->directoryCache[$folderIdentifier]);
		if ($iterator->count() == 0) {
			return array();
		}
		$iterator->seek($start);

		$c = ($numberOfItems > 0) ? $numberOfItems : -1;

		$items = array();
		while ($iterator->valid() && ($numberOfItems == 0 || $c > 0)) {

			$iteratorItem = $iterator->current();
			$identifier = $iterator->key();

			// Go on to the next iterator item now as we might skip this one early.
			$iterator->next();

			if ($this->applyFilterMethodsToDirectoryItem($filterMethods, $iteratorItem['name'], $identifier, $folderIdentifier, isset($itemRows[$identifier]) ? array('indexData' => $itemRows[$identifier]) : array()) === FALSE) {
				continue;
			}

			if (isset($itemRows[$identifier])) {
				list($name, $fileInfo) = $this->{$itemHandlerMethod}($iteratorItem, $itemRows[$identifier]);
			} else {
				list($name, $fileInfo) = $this->{$itemHandlerMethod}($iteratorItem);
			}

			if (empty($fileInfo)) {
				continue;
			}

			if ($recursive) {
				$key = $identifier;
			}

			$items[$name] = $fileInfo;

			--$c;
		}

		return $items;
	}

	/**
	 * Handler for items in a file list.
	 *
	 * @param array $fileInfo
	 * @param array $fileRow The pre-loaded file row
	 * @return array
	 */
	protected function getFileList_itemCallback($fileInfo, $fileRow = array()) {
		if ($fileInfo['isDirectory'] === TRUE) {
			return array('', array());
		}
		// TODO: What do with the strange date format "Aug  1 09:33"?
		// if (!empty($fileRow) && filemtime($filePath) <= $fileRow['mtime']) {
		if (!empty($fileRow)) {
			return array($fileInfo['name'], $fileRow);
		} else {
			return array($fileInfo['name'], $fileInfo);
		}
	}

	/**
	 * Handler for items in a directory listing.
	 *
	 * @param array $folderInfo
	 * @return array
	 */
	protected function getFolderList_itemCallback($folderInfo) {
		if ($folderInfo['isDirectory'] === FALSE) {
			return array('', array());
		}
		return array($folderInfo['name'], $folderInfo);
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

		$hookParameters = array(
			'fileInfo' => &$fileInfo,
			'line' => $line,
		);

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine'])) {
			ksort($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine']);
			foreach($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php']['parseResultLine'] as $hookFunction) {
				$hookResult = \TYPO3\CMS\Core\Utility\GeneralUtility::callUserFunction($hookFunction, $hookParameters, $this, '', 1);
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
			$identifier = $this->canonicalizeAndCheckFolderPath($folderIdentifier . $fileInfo['name']);
		} else {
			$identifier = $this->canonicalizeAndCheckFilePath($folderIdentifier . $fileInfo['name']);
		}

		$fileInfo['identifier'] = $identifier;
		$fileInfo['storage'] = $this->storageUid;
		$fileInfo['parseRule'] = $hookFunction;

		if ($fileInfo['isDirectory'] === FALSE) {
			$fileInfo['mimetype'] = $this->getMimeType($fileInfo['name']);
		}

		$this->directoryCache[$folderIdentifier][$identifier] = $fileInfo;
	}

	/**
	 * Changes the current directory on a FTP server.
	 *
	 * @param string $identifier
	 * @return boolean
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
	 * @throws \RuntimeException
	 */
	protected function getRawList($folderIdentifier, $fileName = '') {
		if ($this->changeDirectory($folderIdentifier) === FALSE) {
			throw new \RuntimeException('Changing directory "' . $folderIdentifier . '" faild.', 1407049647);
		}
		// The -a option is used to show the hidden files as well on some FTP servers.
		$result = @ftp_rawlist($this->stream, '-a ' . $fileName);
		if ($result === FALSE) {
			throw new \RuntimeException('FTP error: Fetching directory list of "' . $folderIdentifier . '" faild.', 1407049747);
		}
		// Some servers do not return anything when using -a, so in that case try again without the -a option.
		if (sizeof($result) <= 1) {
			$result = ftp_rawlist($this->stream, $fileName);
			if ($result === FALSE) {
				throw new \RuntimeException('FTP error: Fetching directory list of "' . $folderIdentifier . '" faild.', 1407049747);
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
			throw new \RuntimeException('FTP error: Fetching list of directory "' . $oldIdentifier . '" faild.', 1407049647);
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
		return $this->basePath . $identifier;
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

	/**
	 * Gets the charset conversion object.
	 *
	 * @return \TYPO3\CMS\Core\Charset\CharsetConverter
	 */
	protected function getCharsetConversion() {
		if (!isset($this->charsetConversion)) {
			if (TYPO3_MODE === 'FE') {
				$this->charsetConversion = $GLOBALS['TSFE']->csConvObj;
			} elseif (is_object($GLOBALS['LANG'])) {
				// BE assumed:
				$this->charsetConversion = $GLOBALS['LANG']->csConvObj;
			} else {
				// The object may not exist yet, so we need to create it now. Happens in the Install Tool for example.
				$this->charsetConversion = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Charset\\CharsetConverter');
			}
		}
		return $this->charsetConversion;
	}

	/**
	 * Backports from TYPO3 v6.2.x.
	 */

	/**
	 * Returns the identifier of the folder the file resides in
	 *
	 * @param string $fileIdentifier
	 * @return mixed
	 */
	public function getParentFolderIdentifierOfIdentifier($fileIdentifier) {
		$fileIdentifier = $this->canonicalizeAndCheckFilePath($fileIdentifier);
		$parentIdentifier = \TYPO3\CMS\Core\Utility\PathUtility::dirname($fileIdentifier);
		if ($parentIdentifier === '/') {
			return $parentIdentifier;
		}
		return $parentIdentifier . '/';
	}

	/**
	 * Returns a temporary path for a given file, including the file extension.
	 *
	 * @param string $fileIdentifier
	 * @return string
	 */
	protected function getTemporaryPathForFile($fileIdentifier) {
		return \TYPO3\CMS\Core\Utility\GeneralUtility::tempnam('fal-tempfile-', '.' . \TYPO3\CMS\Core\Utility\PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION));
	}
}


?>
