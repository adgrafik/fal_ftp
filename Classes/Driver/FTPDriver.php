<?php
namespace AdGrafik\FalFtp\Driver;

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

use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class FTPDriver extends \TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver {

	/**
	 * @var string
	 */
	const UNSAFE_FILENAME_CHARACTER_EXPRESSION = '\\x00-\\x2C\\/\\x3A-\\x3F\\x5B-\\x60\\x7B-\\xBF';

	/**
	 * A list of all supported hash algorithms, written all lower case and
	 * without any dashes etc. (e.g. sha1 instead of SHA-1)
	 * Be sure to set this in inherited classes!
	 *
	 * @var array
	 */
	protected $supportedHashAlgorithms = array('sha1', 'md5');

	/**
	 * @var array $extensionConfiguration
	 */
	protected $extensionConfiguration;

	/**
	 * The $directoryCache caches all files including file info which are loaded via FTP.
	 * This cache get refreshed only when an user action is done or file is processed.
	 *
	 * @var array $directoryCache
	 */
	protected $directoryCache;

	/**
	 * In this stack all created temporary files are cached. Sometimes a temporary file 
	 * already exist. In this case use the file which was downloaded already.
	 *
	 * @var array $temporaryFileStack
	 */
	protected $temporaryFileStack;

	/**
	 * Limit Thumbnails Rendering: This option can be used to reduce file rendering 
	 * in the backend. Usually if a thumbnail is created it have to be downloaded first, 
	 * generated and then uploaded again. With this option it'p possible to define 
	 * a maximum file size where thumbnails created. If set a local image will be 
	 * taken as placeholder. Set this option to "0" will deactivate this function.
	 *
	 * @var integer $createThumbnailsUpToSize
	 */
	protected $createThumbnailsUpToSize;

	/**
	 * Default Thumbnails: Path to thumbnail image which is displayed 
	 * when "createThumbnailsUpToSize" is set.
	 *
	 * @var string $defaultThumbnail
	 */
	protected $defaultThumbnail;

	/**
	 * Fetch Real Modification Time: By default the modification time is generated at listing 
	 * and depends on what FTP server returns. Usually this is enough information. 
	 * If this feature ist set, the modification time is fetched by the function ftp_mdtm 
	 * and overwrite the time of the list if it is available. But not all servers support 
	 * this feature and it will slow down the file listing.
	 *
	 * @var string $exactModificationTime
	 */
	protected $exactModificationTime;

	/**
	 * Enable Remote Service: If this option is set, a service file is uploaded 
	 * to the FTP server which handles some operations to avoid too much downloading. 
	 *
	 * @var string $remoteService
	 */
	protected $remoteService;

	/**
	 * Encryption key for remote service. 
	 *
	 * @var string $remoteServiceEncryptionKey
	 */
	protected $remoteServiceEncryptionKey;

	/**
	 * Encryption key for remote service. 
	 *
	 * @var string $remoteServiceFileName
	 */
	protected $remoteServiceFileName;

	/**
	 * Additional header to send with cUrl. 
	 *
	 * @var string $remoteServiceAdditionalHeaders
	 */
	protected $remoteServiceAdditionalHeaders;

	/**
	 * The base path defined in the FTP settings. Must not be the absolute path!
	 *
	 * @var string $basePath
	 */
	protected $basePath;

	/**
	 * The public URL from the FTP server
	 *
	 * @var array $publicUrl
	 */
	protected $publicUrl;

	/**
	 * @var \AdGrafik\FalFtp\FTPClient\FTP $ftpClient
	 */
	protected $ftpClient;

	/**
	 * @return void
	 */
	public function __construct(array $configuration = array()) {
		parent::__construct($configuration);

		// The capabilities default of this driver. See CAPABILITY_* constants for possible values
		$this->capabilities =
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_BROWSABLE |
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_PUBLIC |
			\TYPO3\CMS\Core\Resource\ResourceStorage::CAPABILITY_WRITABLE;

		// Get and set extension configuration.
		$this->extensionConfiguration = (array) @unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['fal_ftp']);
		$this->directoryCache = array();
		$this->temporaryFileStack = array();
	}

	/**
	 * Initializes this object. This is called by the storage after the driver
	 * has been attached.
	 *
	 * @return void
	 */
	public function __destruct() {
		// Delete all temporary files after processing.
		$temporaryPattern = PATH_site . 'typo3temp/fal-ftp-tempfile-*';
		array_map('unlink', glob($temporaryPattern));
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
	 * processes the configuration, should be overridden by subclasses
	 *
	 * @return void
	 */
	public function processConfiguration() {

		// Throw deprecation message if hooks defined.
		if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['fal_ftp/Classes/Hook/ListParser.php'])) {
			GeneralUtility::deprecationLog('Hook for fal_ftp parser "$GLOBALS[\'TYPO3_CONF_VARS\'][\'SC_OPTIONS\'][\'fal_ftp/Classes/Hook/ListParser.php\']" is deprecated. Use "AdGrafik\\FalFtp\\FTPClient\\ParserRegistry->registerParser" instead.');
		}

		$this->createThumbnailsUpToSize = (integer) @$this->extensionConfiguration['ftpDriver.']['createThumbnailsUpToSize'];
		$this->defaultThumbnail = GeneralUtility::getFileAbsFileName(@$this->extensionConfiguration['ftpDriver.']['defaultThumbnail'] ?: 'EXT:fal_ftp/Resources/Public/Images/default_image.png');
		$this->exactModificationTime = (isset($this->extensionConfiguration['ftpDriver.']['exactModificationTime']) && $this->extensionConfiguration['ftpDriver.']['exactModificationTime']);
		$this->remoteService = (isset($this->extensionConfiguration['remoteService']) && $this->extensionConfiguration['remoteService']);
		$this->remoteServiceEncryptionKey = md5(@$this->extensionConfiguration['remoteService.']['encryptionKey'] ?: $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
		$this->remoteServiceFileName = '/' . trim(@$this->extensionConfiguration['remoteService.']['fileName'] ?: '.FalFtpRemoteService.php');
		$this->remoteServiceAdditionalHeaders = GeneralUtility::trimExplode(';', (string) @$this->extensionConfiguration['remoteService.']['additionalHeaders']);

		// Check if curlUse is activated.
		if ($this->remoteService && $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlUse'] != '1') {
			$this->addFlashMessage('cURL configuration is not activated. $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'curlUse\']');
		}

		// Set driver configuration.
		$this->basePath = '/' . trim($this->configuration['basePath'], '/');
		$this->publicUrl = trim($this->configuration['publicUrl'], '/');

		$this->configuration['timeout'] = (integer) @$this->extensionConfiguration['ftpDriver.']['timeout'] ?: 90;
		$this->configuration['ssl'] = (isset($this->configuration['ssl']) && $this->configuration['ssl']);
		// Configuration parameter "mode" deprecated. Use passiveMode instead.
		if (isset($this->configuration['mode']) && isset($this->configuration['passiveMode']) === FALSE) {
			$this->configuration['passiveMode'] = ($this->configuration['mode'] === 'passiv');
		}

		// Connect to FTP server.
		try {
			$this->ftpClient = GeneralUtility::makeInstance('AdGrafik\\FalFtp\\FTPClient\\FTP', $this->configuration)
				 ->connect($this->configuration['username'], $this->configuration['password'], $this->configuration['ssl']);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception $exception) {
			$this->addFlashMessage('FTP error: ' . $exception->getMessage());
		}
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
	 * Get ftpClient
	 *
	 * @return resource
	 */
	public function getFtpClient() {
		return $this->ftpClient;
	}

	/**
	 * Returns the public URL to a file.
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
	 * Checks if a folder exists
	 *
	 * @param string $identifier
	 * @return boolean
	 */
	public function folderExists($identifier) {
		$identifier = $this->canonicalizeAndCheckFolderIdentifier($identifier);
		return $this->ftpClient->directoryExists($identifier);
	}

	/**
	 * Returns the identifier of the default folder new files should be put into.
	 *
	 * @return string
	 */
	public function getDefaultFolder() {
		$folderIdentifier = '/user_upload/';
		if ($this->folderExists($folderIdentifier) === FALSE) {
			try {
				$folderIdentifier = $this->createFolder('user_upload', '/');
			} catch (\RuntimeException $e) {
				/** @var StorageRepository $storageRepository */
				$storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
				$storage = $storageRepository->findByUid($this->storageUid);
				if ($storage->isWritable()) {
					throw $e;
				}
			}
		}
		return $folderIdentifier;
	}

	/**
	 * Checks if a folder inside a folder exists.
	 *
	 * @param string $folderName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, $folderIdentifier) {
		return $this->ftpClient->directoryExists($folderIdentifier . $folderName);

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
		return GeneralUtility::isFirstPartOfStr($entryIdentifier, $folderIdentifier);
	}

	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param string $folderIdentifier
	 * @return boolean TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty($folderIdentifier) {
		$this->fetchDirectoryList($folderIdentifier, TRUE);
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
	 * @throws \RuntimeException Thrown at FTP error.
	 */
	public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = FALSE) {

		$newFolderName = $this->sanitizeFileName($newFolderName);
		$folderIdentifier = $parentFolderIdentifier . $newFolderName . '/';

		try {
			$this->ftpClient->createDirectory($folderIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Creating folder "' . $folderIdentifier . '" faild.', 1408550550);
		}

		$this->fetchDirectoryList($parentFolderIdentifier, TRUE);

		return $folderIdentifier;
	}

	/**
	 * Renames a folder in this storage.
	 *
	 * @param string $folderIdentifier
	 * @param string $newName
	 * @return array A map of old to new file identifiers of all affected resources
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException Thrown at FTP error.
	 */
	public function renameFolder($folderIdentifier, $newName) {

		$newFolderIdentifier = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier) . $this->sanitizeFileName($newName) . '/';

		// Create a mapping from old to new identifiers
		$identifierMap = $this->createIdentifierMap($folderIdentifier, $newFolderIdentifier);

		try {
			$this->ftpClient->renameDirectory($folderIdentifier, $newFolderIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Folder "' . $folderIdentifier . '" already exists.', 1408550551);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Renaming folder "' . $folderIdentifier . '" faild.', 1408550552);
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
		try {
			$this->ftpClient->deleteDirectory($folderIdentifier, $recursively);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Deleting folder "' . $folderIdentifier . '" faild.', 1408550553);
		}
		return TRUE;
	}

	/**
	 * Folder equivalent to moveFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 * @return array All files which are affected, map of old => new file identifiers
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException
	 */
	public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {

		$newIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier . $newFolderName);

		// Create a mapping from old to new identifiers
		$identifierMap = $this->createIdentifierMap($sourceFolderIdentifier, $newIdentifier);

		try {
			$this->ftpClient->moveDirectory($sourceFolderIdentifier, $newIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Folder "' . $newIdentifier . '" already exists.', 1408550554);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Moving folder "' . $sourceFolderIdentifier . '" faild.', 1408550555);
		}

		return $identifierMap;
	}

	/**
	 * Folder equivalent to copyFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException
	 */
	public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {

		$targetIdentifier = $targetFolderIdentifier . $folderName . '/';

		try {
			$this->ftpClient->copyDirectory($sourceFolderIdentifier, $targetIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException('Source folder "' . $sourceFolderIdentifier . '" not exists', 1408550556);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Target folder "' . $targetIdentifier . '" already exists', 1408550557);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Copying folder "' . $sourceFolderIdentifier . '" faild.', 1408550558);
		}

		return TRUE;
	}

	/**
	 * Checks if a file exists.
	 *
	 * @param string $fileIdentifier
	 * @return boolean
	 */
	public function fileExists($fileIdentifier) {
		return $this->ftpClient->fileExists($fileIdentifier);

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
			$fileName = $this->getNameFromIdentifier($fileIdentifier);
			$this->fetchDirectoryList($folderIdentifier, TRUE);

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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException
	 */
	public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = TRUE) {

		$newFileIdentifier = $targetFolderIdentifier . $this->sanitizeFileName($newFileName);

		try {
			$this->ftpClient->uploadFile($newFileIdentifier, $localFilePath);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $localFilePath . '" not exists', 1408550561);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Target file "' . $newFileIdentifier . '" already exists', 1408550562);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Adding file "' . $newFileIdentifier . '" faild.', 1408550563);
		}

		if ($removeOriginal) {
			unlink($localFilePath);
		}

		$this->fetchDirectoryList($targetFolderIdentifier, TRUE);

		return $newFileIdentifier;
	}

	/**
	 * Creates a new (empty) file and returns the identifier.
	 *
	 * @param string $fileName
	 * @param string $parentFolderIdentifier
	 * @return string
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 * @throws \RuntimeException
	 */
	public function createFile($fileName, $parentFolderIdentifier) {

		if ($this->isValidFilename($fileName) === FALSE) {
			throw new \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException('Invalid characters in fileName "' . $fileName . '"', 1408550564);
		}

		$fileIdentifier = $parentFolderIdentifier . $this->sanitizeFileName($fileName);

		try {
			$this->ftpClient->createFile($fileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('File "' . $fileIdentifier . '" already exists', 1408550565);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Creating file "' . $fileIdentifier . '" faild.', 1408550566);
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
	 * @throws \RuntimeException if renaming the file failed
	 */
	public function renameFile($fileIdentifier, $newName) {

		$newFileIdentifier = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier) . $this->sanitizeFileName($newName);

		try {
			$this->ftpClient->renameFile($fileIdentifier, $newFileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('File "' . $fileIdentifier . '" already exists', 1408550567);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Renaming file "' . $fileIdentifier . '" faild.', 1408550568);
		}

		return $newFileIdentifier;
	}

	/**
	 * Replaces the contents (and file-specific metadata) of a file object with a local file.
	 *
	 * @param string $fileIdentifier
	 * @param string $localFilePath
	 * @return boolean TRUE if the operation succeeded
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \RuntimeException
	 */
	public function replaceFile($fileIdentifier, $localFilePath) {

		try {
			$this->ftpClient->replaceFile($fileIdentifier, $localFilePath);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $localFilePath . '" not exists', 1408550569);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Replacing file "' . $fileIdentifier . '" faild.', 1408550570);
		}

		return TRUE;
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

		try {
			$this->ftpClient->deleteFile($fileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Deleting file "' . $fileIdentifier . '" faild.', 1408550571);
		}

		return TRUE;
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

		try {
			$bytes = $this->ftpClient->setFileContents($fileIdentifier, $contents);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception $exception) {
			throw new \RuntimeException('Setting file contents of file "' . $fileIdentifier . '" faild.', 1408550572);
		}

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

		try {
			$contents = $this->ftpClient->getFileContents($fileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception $exception) {
			throw new \RuntimeException('Setting file contents of file "' . $fileIdentifier . '" faild.', 1408550573);
		}

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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \RuntimeException
	 */
	public function getFileForLocalProcessing($fileIdentifier, $writable = TRUE) {

		$temporaryFile = $this->getTemporaryPathForFile($fileIdentifier);

		// Prevent creating thumbnails if file size greater than the defined in the extension configuration.
		if (TYPO3_MODE === 'BE' && $this->createThumbnailsUpToSize) {
			$fileInfo = $this->getFileInfoByIdentifier($fileIdentifier);
			if ($fileInfo['size'] > $this->createThumbnailsUpToSize) {
				copy($this->defaultThumbnail, $temporaryFile);
				return $temporaryFile;
			}
		}

		try {
			$this->ftpClient->downloadFile($fileIdentifier, $temporaryFile);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $temporaryFile . '" not exists', 1408550574);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Copying file "' . $fileIdentifier . '" to temporary file faild.', 1408550575);
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
		echo $this->getFileContents($identifier);
	}

	/**
	 * Moves a file *within* the current storage.
	 * Note that this is only about an inner-storage move action,
	 * where a file is just moved to another folder in the same storage.
	 *
	 * @param string $sourceFileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName
	 * @return array A map of old to new file identifiers
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException
	 * @throws \RuntimeException
	 */
	public function moveFileWithinStorage($sourceFileIdentifier, $targetFolderIdentifier, $newFileName) {

		$targetFileIdentifier = $this->canonicalizeAndCheckFileIdentifier($targetFolderIdentifier . $newFileName);

		try {
			$this->ftpClient->moveFile($sourceFileIdentifier, $targetFileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException('File "' . $targetFileIdentifier . '" already exists.', 1408550576);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Moving file "' . $sourceFileIdentifier . '" faild.', 1408550577);
		}

		$this->fetchDirectoryList($this->getParentFolderIdentifierOfIdentifier($sourceFileIdentifier), TRUE);
		$this->fetchDirectoryList($targetFolderIdentifier, TRUE);

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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException
	 * @throws \RuntimeException
	 */
	public function copyFileWithinStorage($sourceFileIdentifier, $targetFolderIdentifier, $fileName) {

		$targetFileIdentifier = $targetFolderIdentifier . $fileName;

		try {
			$this->ftpClient->copyFile($sourceFileIdentifier, $targetFileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $sourceFileIdentifier . '" not exists', 1408550578);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException('Target file "' . $targetFileIdentifier . '" already exists', 1408550579);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Copying file "' . $sourceFileIdentifier . '" faild.', 1408550580);
		}

		return $targetFileIdentifier;
	}


	/**
	 * Returns the permissions of a file/folder as an array (keys r, w) of boolean flags
	 *
	 * @param string $identifier
	 * @return array
	 * @throws \RuntimeException
	 */
	public function getPermissions($identifier) {

		if (substr($identifier, -1) === '/') {
			$resourceInfo = $this->getFolderInfoByIdentifier($identifier);
		} else {
			$resourceInfo = $this->getFileInfoByIdentifier($identifier);
		}

		if (isset($resourceInfo['mode']) && is_array($resourceInfo['mode'])) {
			return $resourceInfo['mode'];
		} else {
			return array(
				'r' => TRUE,
				'w' => TRUE,
			);
		}
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
			throw new \InvalidArgumentException('Hash algorithm "' . $hashAlgorithm . '" is not supported.', 1408550581);
		}

		if ($this->remoteService) {

			$request = array(
				'action' => 'hashFile',
				'parameters' => array(
					'fileIdentifier' => $fileIdentifier,
					'hashAlgorithm' => $hashAlgorithm,
				),
			);
			$response = $this->sendRemoteService($request);

			if ($response['result'] === FALSE) {
				throw new \RuntimeException($response['message'], 1408550682);
			}

			$hash = $response['hash'];

		} else {

			$temporaryFile = $this->getFileForLocalProcessing($fileIdentifier);

			switch ($hashAlgorithm) {
				case 'sha1':
					$hash = sha1_file($temporaryFile);
					break;
				case 'md5':
					$hash = md5_file($temporaryFile);
					break;
				default:
					throw new \RuntimeException('Hash algorithm ' . $hashAlgorithm . ' is not implemented.', 1408550582);
			}
		}

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

		$this->fetchDirectoryList($folderIdentifier);

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
	 * This function scans an ftp_rawlist line string and returns its parts.
	 *
	 * @param string $folderIdentifier
	 * @param boolean $resetCache
	 * @return array
	 */
	protected function fetchDirectoryList($folderIdentifier, $resetCache = FALSE) {

		if ($resetCache === FALSE && is_array($this->directoryCache[$folderIdentifier])) {
			return $this->directoryCache[$folderIdentifier];
		}
		$this->directoryCache[$folderIdentifier] = array();

		return $this->ftpClient->fetchDirectoryList($folderIdentifier, array($this, 'fetchDirectoryList_itemCallback'));
	}

	/**
	 * Callback function of line parsing. Adds additional file information.
	 *
	 * @param array $resourceInfo
	 * @param \AdGrafik\FalFtp\FTPClient\FTP $parentObject
	 * @return void
	 */
	public function fetchDirectoryList_itemCallback($resourceInfo, $parentObject) {

		if ($resourceInfo['isDirectory']) {
			$identifier = $this->canonicalizeAndCheckFolderIdentifier($resourceInfo['path'] . $resourceInfo['name']);
		} else {
			$identifier = $this->canonicalizeAndCheckFileIdentifier($resourceInfo['path'] . $resourceInfo['name']);
		}

		$resourceInfo['storage'] = $this->storageUid;
		$resourceInfo['identifier'] = $identifier;
		$resourceInfo['identifier_hash'] = $this->hashIdentifier($identifier);
		$resourceInfo['folder_hash'] = $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($identifier));
		$resourceInfo['ctime'] = 0;
		$resourceInfo['atime'] = 0;
		$resourceInfo['mode'] = array(
			'r' => (@$resourceInfo['mode'][0] == 'r'),
			'w' => (@$resourceInfo['mode'][1] == 'w'),
		);

		if ($this->exactModificationTime) {
			try {
				$resourceInfo['mtime'] = $this->ftpClient->getModificationTime($identifier);
			} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
				// Ignore on failure.
			}
		}

		$this->directoryCache[$resourceInfo['path']][$identifier] = $resourceInfo;
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

		if ($this->ftpClient->directoryExists($oldIdentifier) === FALSE) {
			$identifierMap[$oldIdentifier] = $newIdentifier;
			return $identifierMap;
		}

		// If is a directory, make valid identifier.
		$oldIdentifier = rtrim($oldIdentifier, '/') . '/';
		$newIdentifier = rtrim($newIdentifier, '/') . '/';
		$identifierMap[$oldIdentifier] = $newIdentifier;

		try {
			$directoryList = $this->ftpClient->fetchDirectoryList($oldIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception $exception) {
			throw new \RuntimeException('Fetching list of directory "' . $oldIdentifier . '" faild.', 1408550584);
		}

		foreach ($directoryList as &$resourceInfo) {
			$this->createIdentifierMap($oldIdentifier . $resourceInfo['name'], $newIdentifier . $resourceInfo['name'], $identifierMap);
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
	protected function getNameFromIdentifier($identifier) {
		return trim(PathUtility::basename($identifier), '/');
	}

	/**
	 * Communication function for the remote service.
	 *
	 * @param array $request
	 * @param boolean $createOnFail
	 * @return array
	 * @throws \RuntimeException
	 */
	protected function sendRemoteService($request = array(), $createOnFail = TRUE) {

		$request['encryptionKey'] = $this->remoteServiceEncryptionKey;
		$requestUrl = $this->getPublicUrl($this->remoteServiceFileName) . '?' . http_build_query($request);
		$headers = count($this->remoteServiceAdditionalHeaders) ? $this->remoteServiceAdditionalHeaders : FALSE;

		$response = GeneralUtility::getUrl($requestUrl, 0, $headers);
		$response = @json_decode($response, TRUE);

		if (is_array($response) === FALSE && isset($response['result']) === FALSE) {
			// Define default error message before.
			$response = array(
				'result' => FALSE,
				'message' => 'Remote service communication faild.',
			);
			// If fails, renew the remote service and try again.
			if ($createOnFail) {
				$remoteServiceContents = file_get_contents(GeneralUtility::getFileAbsFileName('EXT:fal_ftp/Resources/Private/Script/.FalFtpRemoteService.php'));
				$remoteServiceContents = str_replace('###ENCRYPTION_KEY###', $this->remoteServiceEncryptionKey, $remoteServiceContents);
				$this->ftpClient->setFileContents('/.FalFtpRemoteService.php', $remoteServiceContents);
				$response = $this->sendRemoteService($request, FALSE);
			}
		}

		return $response;
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
		/** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
		$defaultFlashMessageQueue = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessageService')->getMessageQueueByIdentifier();
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
	 * Returns a temporary path for a given file, including the file extension.
	 *
	 * @param string $fileIdentifier
	 * @return string
	 */
	protected function getTemporaryPathForFile($fileIdentifier) {

		// Sometimes a temporary file already exist. In this case use the file which was downloaded already.
		$hash = sha1($this->storageUid . ':' . $fileIdentifier);
		if (isset($this->temporaryFileStack[$hash])) {
			return $this->temporaryFileStack[$hash];
		}

		return $this->temporaryFileStack[$hash] = parent::getTemporaryPathForFile($fileIdentifier);
#\TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($this, 'getFileInfoByIdentifier');
#\TYPO3\CMS\Core\Utility\DebugUtility::debug(__FUNCTION__, 'Method');
	}
}


?>