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

use \TYPO3\CMS\Core\Utility\PathUtility;
use \TYPO3\CMS\Core\Utility\GeneralUtility;

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
	 * Initializes this object. This is called by the storage after the driver
	 * has been attached.
	 *
	 * @return void
	 */
	public function initialize() {
		// Backports $storageUid from TYPO3 v6.2.x.
		$this->storageUid = $this->storage->getUid();
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
	 * Get ftp
	 *
	 * @return resource
	 */
	public function getFtp() {
		return $this->ftp;
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
	 * Checks if a resource exists - does not care for the type (file or folder).
	 *
	 * @param string $identifier
	 * @return boolean
	 */
	public function resourceExists($identifier) {
		return $this->ftpClient->resourceExists($identifier);
	}

	/**
	 * Checks if a folder exists
	 *
	 * @param string $identifier
	 * @return boolean
	 */
	public function folderExists($identifier) {
		return $this->ftpClient->directoryExists($identifier);
	}

	/**
	 * Checks if a file inside a storage folder exists.
	 *
	 * @param string $folderName
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, \TYPO3\CMS\Core\Resource\Folder $folder) {
		return $this->ftpClient->directoryExists($folder->getIdentifier() . $folderName);
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

		return GeneralUtility::isFirstPartOfStr($content, $folderIdentifier);
	}

	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @return boolean TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty(\TYPO3\CMS\Core\Resource\Folder $folder) {
		$folderIdentifier = $folder->getIdentifier();
		$this->fetchDirectoryList($folderIdentifier, TRUE);
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
		$folderInfo = $this->getFileInfoByIdentifier($folder->getIdentifier());
		if (isset($folderInfo['mode']) && is_array($folderInfo['mode'])) {
			return $folderInfo['mode'];
		} else {
			return array(
				'r' => TRUE,
				'w' => TRUE,
			);
		}
	}

	/**
	 * Creates a folder.
	 *
	 * @param string $folderName
	 * @param \TYPO3\CMS\Core\Resource\Folder $parentFolder
	 * @return \TYPO3\CMS\Core\Resource\Folder The new (created) folder object
	 * @throws \RuntimeException Thrown at FTP error.
	 */
	public function createFolder($folderName, \TYPO3\CMS\Core\Resource\Folder $parentFolder) {

		$folderName = $this->sanitizeFileName($folderName);
		$folderIdentifier = $parentFolder->getIdentifier() . $folderName . '/';

		try {
			$this->ftpClient->createDirectory($folderIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Creating folder "' . $folderIdentifier . '" faild.', 1408550550);
		}

		$this->fetchDirectoryList($parentFolder->getIdentifier(), TRUE);

		return \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->createFolderObject($this->storage, $folderIdentifier, $folderName);
	}

	/**
	 * Renames a folder in this storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param string $newName The target path (including the file name!)
	 * @return array A map of old to new file identifiers
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException Thrown at FTP error.
	 */
	public function renameFolder(\TYPO3\CMS\Core\Resource\Folder $folder, $newName) {

		$folderIdentifier = $folder->getIdentifier();
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
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param boolean $recursively
	 * @return boolean
	 * @throws \RuntimeException
	 */
	public function deleteFolder(\TYPO3\CMS\Core\Resource\Folder $folder, $recursively = FALSE) {
		try {
			$this->ftpClient->deleteDirectory($folder->getIdentifier(), $recursively);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Deleting folder "' . $folder->getIdentifier() . '" faild.', 1408550553);
		}
		return TRUE;
	}

	/**
	 * Folder equivalent to moveFileWithinStorage().
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $sourceFolder
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $newFolderName
	 * @return array A map of old to new file identifiers
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException
	 */
	public function moveFolderWithinStorage(\TYPO3\CMS\Core\Resource\Folder $sourceFolder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFolderName) {

		$oldIdentifier = $sourceFolder->getIdentifier();
		$newIdentifier = $this->canonicalizeAndCheckFolderPath($targetFolder->getIdentifier() . $newFolderName);

		// Create a mapping from old to new identifiers
		$identifierMap = $this->createIdentifierMap($oldIdentifier, $newIdentifier);

		try {
			$this->ftpClient->moveDirectory($oldIdentifier, $newIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Folder "' . $newIdentifier . '" already exists.', 1408550554);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Moving folder "' . $oldIdentifier . '" faild.', 1408550555);
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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException
	 */
	public function copyFolderWithinStorage(\TYPO3\CMS\Core\Resource\Folder $sourceFolder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $folderName) {

		$sourceIdentifier = $sourceFolder->getIdentifier();
		$targetIdentifier = $targetFolder->getIdentifier() . $folderName . '/';

		try {
			$this->ftpClient->copyDirectory($sourceIdentifier, $targetIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException('Source folder "' . $sourceIdentifier . '" not exists', 1408550556);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Target folder "' . $targetIdentifier . '" already exists', 1408550557);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Copying folder "' . $sourceIdentifier . '" faild.', 1408550558);
		}

		return TRUE;
	}

	/**
	 * Move a folder from another storage.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param \TYPO3\CMS\Core\Resource\Folder $targetFolder
	 * @param string $folderName
	 * @return boolean
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 */
	public function moveFolderBetweenStorages(\TYPO3\CMS\Core\Resource\Folder $folder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $folderName) {

		if ($this->folderExistsInFolder($folderName, $targetFolder)) {
			// This exception is not shown in the backend...?
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The folder ' . $folderName . ' already exists in folder ' . $targetFolder->getIdentifier(), 1408550559);
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
	 * @return boolean
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 */
	public function copyFolderBetweenStorages(\TYPO3\CMS\Core\Resource\Folder $folder, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $folderName) {

		if ($this->folderExistsInFolder($folderName, $targetFolder)) {
			// This exception is not shown in the backend...?
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('The folder ' . $folderName . ' already exists in folder ' . $targetFolder->getIdentifier(), 1408550560);
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

		$this->fetchDirectoryList($targetIdentifier, TRUE);

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
			$this->fetchDirectoryList($folderIdentifier, TRUE);

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
		$fileInfo = $this->getFileInfoByIdentifier($file->getIdentifier());
		if (isset($fileInfo['mode']) && is_array($fileInfo['mode'])) {
			return $fileInfo['mode'];
		} else {
			return array(
				'r' => TRUE,
				'w' => TRUE,
			);
		}
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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException
	 * @throws \RuntimeException
	 */
	// TODO check if this is still necessary if we move more logic to the storage
	public function addFileRaw($sourceIdentifier, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFileName) {

		$targetFolderIdentifier = $targetFolder->getIdentifier();
		$newFileIdentifier = $targetFolderIdentifier . $this->sanitizeFileName($newFileName);

		try {
			$this->ftpClient->uploadFile($newFileIdentifier, $sourceIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $sourceIdentifier . '" not exists', 1408550561);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException('Target file "' . $newFileIdentifier . '" already exists', 1408550562);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Adding file "' . $newFileIdentifier . '" faild.', 1408550563);
		}

		$this->fetchDirectoryList($targetFolderIdentifier, TRUE);

		return $newFileIdentifier;
	}

	/**
	 * Creates a new file and returns the matching file object for it.
	 *
	 * @param string $fileName
	 * @param \TYPO3\CMS\Core\Resource\Folder $parentFolder
	 * @return \TYPO3\CMS\Core\Resource\File
	 * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 * @throws \RuntimeException
	 */
	public function createFile($fileName, \TYPO3\CMS\Core\Resource\Folder $parentFolder) {

		if ($this->isValidFilename($fileName) === FALSE) {
			throw new \TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException('Invalid characters in fileName "' . $fileName . '"', 1408550564);
		}

		$fileIdentifier = $parentFolder->getIdentifier() . $this->sanitizeFileName($fileName);

		try {
			$this->ftpClient->createFile($fileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException('File "' . $fileIdentifier . '" already exists', 1408550565);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Creating file "' . $fileIdentifier . '" faild.', 1408550566);
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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
	 * @throws \RuntimeException if renaming the file failed
	 */
	public function renameFile(\TYPO3\CMS\Core\Resource\FileInterface $file, $newName) {

		$fileIdentifier = $file->getIdentifier();
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
	 * @param \TYPO3\CMS\Core\Resource\AbstractFile $file
	 * @param string $localFilePath
	 * @return boolean
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \RuntimeException
	 */
	public function replaceFile(\TYPO3\CMS\Core\Resource\AbstractFile $file, $localFilePath) {

		$fileIdentifier = $file->getIdentifier();

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
	 * Removes a file from this storage. This does not check if the file is
	 * still used or if it is a bad idea to delete it for some other reason
	 * this has to be taken care of in the upper layers (e.g. the Storage)!
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return boolean TRUE if deleting the file succeeded
	 */
	public function deleteFile(\TYPO3\CMS\Core\Resource\FileInterface $file) {
		$fileIdentifier = $file->getIdentifier();
		return $this->deleteFileRaw($fileIdentifier);
	}

	/**
	 * Deletes a file without access and usage checks.
	 * This should only be used internally.
	 *
	 * This accepts an identifier instead of an object because we might want to
	 * delete files that have no object associated with (or we don't want to
	 * create an object for) them - e.g. when moving a file to another storage.
	 *
	 * @param string $fileIdentifier
	 * @return boolean TRUE if removing the file succeeded
	 * @throws \RuntimeException
	 */
	public function deleteFileRaw($fileIdentifier) {

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
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @param string $contents
	 * @return integer The number of bytes written to the file
	 * @throws \RuntimeException
	 */
	public function setFileContents(\TYPO3\CMS\Core\Resource\FileInterface $file, $contents) {

		$fileIdentifier = $file->getIdentifier();

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
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return string The file contents
	 * @throws \RuntimeException
	 */
	public function getFileContents(\TYPO3\CMS\Core\Resource\FileInterface $file) {

		$fileIdentifier = $file->getIdentifier();

		try {
			$contents = $this->ftpClient->getFileContents($file->getIdentifier());
		} catch (\AdGrafik\FalFtp\FTPClient\Exception $exception) {
			throw new \RuntimeException('Setting file contents of file "' . $fileIdentifier . '" faild.', 1408550573);
		}

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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \RuntimeException
	 */
	public function copyFileToTemporaryPath(\TYPO3\CMS\Core\Resource\FileInterface $file) {

		$temporaryFile = $this->getTemporaryPathForFile($file);

		// Prevent creating thumbnails if file size greater than the defined in the extension configuration.
		if (TYPO3_MODE === 'BE' && $this->createThumbnailsUpToSize && $file->getSize() > $this->createThumbnailsUpToSize) {
			copy($this->defaultThumbnail, $temporaryFile);
			return $temporaryFile;
		}

		$identifier = $file->getIdentifier();

		try {
			$this->ftpClient->downloadFile($identifier, $temporaryFile);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $temporaryFile . '" not exists', 1408550574);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Copying file "' . $identifier . '" to temporary file faild.', 1408550575);
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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException
	 * @throws \RuntimeException
	 */
	public function moveFileWithinStorage(\TYPO3\CMS\Core\Resource\FileInterface $sourceFile, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $newFileName) {

		$sourceFileIdentifier = $sourceFile->getIdentifier();
		$targetFileIdentifier = $targetFolder->getIdentifier() . $newFileName;

		try {
			$this->ftpClient->moveFile($sourceFileIdentifier, $targetFileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException('File "' . $targetFileIdentifier . '" already exists.', 1408550576);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Moving file "' . $sourceFileIdentifier . '" faild.', 1408550577);
		}

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
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException
	 * @throws \RuntimeException
	 */
	public function copyFileWithinStorage(\TYPO3\CMS\Core\Resource\FileInterface $sourceFile, \TYPO3\CMS\Core\Resource\Folder $targetFolder, $fileName) {

		$sourceFileIdentifier = $sourceFile->getIdentifier();
		$targetFileIdentifier = $targetFolder->getIdentifier() . $fileName;

		try {
			$this->ftpClient->copyFile($sourceFileIdentifier, $targetFileIdentifier);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ResourceDoesNotExistException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException('Source file "' . $sourceFileIdentifier . '" not exists', 1408550578);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\ExistingResourceException $exception) {
			throw new \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileException('Target file "' . $targetFileIdentifier . '" already exists', 1408550579);
		} catch (\AdGrafik\FalFtp\FTPClient\Exception\FTPConnectionException $exception) {
			throw new \RuntimeException('Copying file "' . $sourceFileIdentifier . '" faild.', 1408550580);
		}

		return $this->getFile($targetFileIdentifier);
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
			throw new \InvalidArgumentException('Hash algorithm "' . $hashAlgorithm . '" is not supported.', 1408550581);
		}

		$fileIdentifier = $file->getIdentifier();

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

			$temporaryFile = $this->getFileForLocalProcessing($file);

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
	 * Generic handler method for directory listings - gluing together the
	 * listing items is done
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param array $filterMethods The filter methods used to filter the directory items
	 * @param string $itemHandlerMethod
	 * @param boolean $recursively
	 * @return array
	 */
	protected function getDirectoryItemList($folderIdentifier, $start, $numberOfItems, array $filterMethods, $itemHandlerMethod, $itemRows = array(), $recursively = FALSE) {

		// Actualize processing folder.
		$this->fetchDirectoryList($folderIdentifier);

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

			if ($recursively) {
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
			$identifier = $this->canonicalizeAndCheckFolderPath($resourceInfo['path'] . $resourceInfo['name']);
		} else {
			$identifier = $this->canonicalizeAndCheckFilePath($resourceInfo['path'] . $resourceInfo['name']);
		}

		$resourceInfo['identifier'] = $identifier;
		$resourceInfo['storage'] = $this->storageUid;
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
	 * @return string
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
		$requestUrl = $this->publicUrl . $this->remoteServiceFileName . '?' . http_build_query($request);
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
		$flashMessage = GeneralUtility::makeInstance(
			'TYPO3\\CMS\\Core\\Messaging\\FlashMessage',
			$message,
			'',
			$severity,
			TRUE
		);
		/** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
		$flashMessageService = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessageService');
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
	 * Returns a temporary path for a given file, including the file extension.
	 *
	 * @param param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @return string
	 */
	protected function getTemporaryPathForFile(\TYPO3\CMS\Core\Resource\FileInterface $file) {

		// Sometimes a temporary file already exist. In this case use the file which was downloaded already.
		$hash = sha1($this->storageUid . ':' . $file->getIdentifier());
		if (isset($this->temporaryFileStack[$hash])) {
			return $this->temporaryFileStack[$hash];
		}

		// Image processing needs the file extension for temporary file, 
		// but TYPO3 v6.1 GeneralUtility::tempnam don't supports suffix. 
		// Therefore create the file first and rename it.
		$temporaryFile = GeneralUtility::tempnam('fal-ftp-tempfile-');
		$newTemporaryFile = $temporaryFile . '.' . $file->getExtension();
		rename($temporaryFile, $newTemporaryFile);

		$this->temporaryFileStack[$hash] = $newTemporaryFile;

		return $newTemporaryFile;
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
		$parentIdentifier = PathUtility::dirname($fileIdentifier);
		if ($parentIdentifier === '/') {
			return $parentIdentifier;
		}
		return $parentIdentifier . '/';
#\TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($this, 'getFileInfoByIdentifier');
#\TYPO3\CMS\Core\Utility\DebugUtility::debug(__FUNCTION__, 'Method');
	}
}

?>