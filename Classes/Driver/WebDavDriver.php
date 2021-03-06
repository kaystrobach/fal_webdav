<?php
namespace TYPO3\FalWebdav\Driver;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

include_once __DIR__ . '/../../Resources/Composer/vendor/autoload.php';

use Sabre\DAV;
use Sabre\HTTP\ClientException;
use Sabre\HTTP\ClientHttpException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Resource\Driver\AbstractDriver;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\FalWebdav\Dav\CachingWebDavFrontend;
use TYPO3\FalWebdav\Dav\WebDavFrontend;
use TYPO3\FalWebdav\Dav\WebDavClient;
use TYPO3\FalWebdav\Utility\EncryptionUtility;


/**
 * The driver class for WebDAV storages.
 */
class WebDavDriver extends AbstractHierarchicalFilesystemDriver {

	/**
	 * The base URL of the WebDAV share. Always ends with a trailing slash.
	 *
	 * @var string
	 */
	protected $baseUrl = '';

	/**
	 * The base URL to fetch resources. Includes authentication information if authentication is enabled, so this must
	 * never be published!
	 *
	 * @var string
	 */
	protected $resourceBaseUrl = '';

	/**
	 * The base path of the WebDAV store. This is the URL without protocol, host and port (i.e., only the path on the host).
	 * Always ends with a trailing slash.
	 *
	 * @var string
	 */
	protected $basePath = '';

	/**
	 * @var WebDavClient
	 */
	protected $davClient;

	/**
	 * The username to use for connecting to the storage.
	 *
	 * @var string
	 */
	protected $username = '';

	/**
	 * The password to use for connecting to the storage.
	 *
	 * @var string
	 */
	protected $password = '';

	/**
	 * @var \TYPO3\CMS\Core\Cache\Frontend\AbstractFrontend
	 */
	protected $directoryListingCache;

	/**
	 * @var WebDavFrontend
	 */
	protected $frontend;

	/**
	 * @var \TYPO3\CMS\Core\Log\Logger
	 */
	protected $logger;

	public function __construct(array $configuration = array()) {
		$this->logger = GeneralUtility::makeInstance('TYPO3\CMS\Core\Log\LogManager')->getLogger(__CLASS__);

		parent::__construct($configuration);
	}

	/**
	 * Initializes this object. This is called by the storage after the driver has been attached.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE
			+ ResourceStorage::CAPABILITY_PUBLIC
			+ ResourceStorage::CAPABILITY_WRITABLE;
	}

	/**
	 * Inject method for the DAV client. Mostly useful for unit tests.
	 *
	 * @param WebDavClient $client
	 */
	public function injectDavClient(WebDavClient $client) {
		$this->davClient = $client;
	}

	/**
	 * Only used in tests.
	 *
	 * @param FrontendInterface $cache
	 */
	public function injectDirectoryListingCache(FrontendInterface $cache) {
		$this->directoryListingCache = $cache;
	}

	public function injectFrontend(WebDavFrontend $frontend) {
		$this->frontend = $frontend;
	}

	protected function getFrontend() {
		if (!$this->frontend) {
			$this->frontend = new CachingWebDavFrontend($this->davClient, $this->baseUrl, $this->storageUid, $this->getCache());
		}
		return $this->frontend;
	}

	/**
	 * @return FrontendInterface
	 */
	protected function getCache() {
		if (!$this->directoryListingCache) {
			/** @var CacheManager $cacheManager */
			$cacheManager = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Cache\\CacheManager');
			$this->directoryListingCache = $cacheManager->getCache('tx_falwebdav_directorylisting');
		}

		return $this->directoryListingCache;
	}

	/**
	 * Processes the configuration coming from the storage record and prepares the SabreDAV object.
	 *
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	public function processConfiguration() {
		foreach ($this->configuration as $key => $value) {
			$this->configuration[$key] = trim($value);
		}

		$baseUrl = $this->configuration['baseUrl'];

		$urlInfo = parse_url($baseUrl);
		if ($urlInfo === FALSE) {
			throw new \InvalidArgumentException('Invalid base URL configured for WebDAV driver: ' . $this->configuration['baseUrl'], 1325771040);
		}
		$this->basePath = rtrim($urlInfo['path'], '/') . '/';

		$extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['fal_webdav']);
		$configuration['enableZeroByteFilesIndexing'] = (boolean)$extConf['enableZeroByteFilesIndexing'];

		// Use authentication only if enabled
		$settings = array();
		if ($this->configuration['useAuthentication']) {
			$this->username = $urlInfo['user'] ? $urlInfo['user'] : $this->configuration['username'];
			$this->password = $urlInfo['pass'] ? $urlInfo['pass'] : EncryptionUtility::decryptPassword($this->configuration['password']);
			$settings = array(
				'userName' => $this->username,
				'password' => $this->password
			);
		}

		// make sure the URL contains authentication data and build the resource URL for directly fetching data
		$urlInfo['user'] = $this->username;
		$urlInfo['pass'] = $this->password;
		$this->resourceBaseUrl = rtrim(HttpUtility::buildUrl($urlInfo), '/') . '/';
		// create cleaned URL without credentials
		unset($urlInfo['user']);
		unset($urlInfo['pass']);
		$this->baseUrl = rtrim(HttpUtility::buildUrl($urlInfo), '/') . '/';
		$settings['baseUri'] = $this->baseUrl;

		$this->davClient = new WebDavClient($settings);
		$this->davClient->setThrowExceptions(TRUE);

		$this->davClient->setCertificateVerification($this->configuration['disableCertificateVerification'] != 1);
	}

	/**
	 * Checks if a configuration is valid for this driver.
	 *
	 * Throws an exception if a configuration will not work.
	 *
	 * @param array $configuration
	 * @return void
	 */
	public static function verifyConfiguration(array $configuration) {
		// TODO: Implement verifyConfiguration() method.
	}

	/**
	 * Executes a MOVE request from $oldPath to $newPath.
	 *
	 * @param string $oldPath
	 * @param string $newPath
	 * @return array The result as returned by SabreDAV
	 */
	public function executeMoveRequest($oldPath, $newPath) {
		$oldUrl = $this->baseUrl . ltrim($oldPath, '/');
		$newUrl = $this->baseUrl . ltrim($newPath, '/');

			// force overwriting the file (header Overwrite: T) because the Storage already handled possible conflicts
			// for us
		return $this->executeDavRequest('MOVE', $oldUrl, NULL, array('Destination' => $newUrl, 'Overwrite' => 'T'));
	}

	protected function encodeUrl($url) {
		$urlParts = parse_url($url);
		$urlParts['path'] = implode('/', array_map('rawurlencode', explode('/', $urlParts['path'])));

		return HttpUtility::buildUrl($urlParts);
	}

	/**
	 * Executes a request on the DAV driver.
	 *
	 * @param string $method
	 * @param string $url
	 * @param string $body
	 * @param array $headers
	 * @return array
	 * @throws \Exception If anything goes wrong
	 */
	protected function executeDavRequest($method, $url, $body = NULL, array $headers = array()) {
		try {
			$url = $this->encodeUrl($url);
			return $this->davClient->request($method, $url, $body, $headers);
		} catch (\Sabre\DAV\Exception\NotFound $exception) {
			// If a file is not found, we have to deal with that on a higher level, so throw the exception again
			throw $exception;
		} catch (DAV\Exception $exception) {
			// log all other exceptions
			$this->logger->error(sprintf(
				'Error while executing DAV request. Original message: "%s" (Exception %s, id: %u)',
				$exception->getMessage(), get_class($exception), $exception->getCode()
			));
			// TODO check how we can let this propagate to the driver
			return array();
		}
	}



	/**
	 * Checks if a given resource exists in this DAV share.
	 *
	 * @param string $resourcePath The path to the resource, i.e. a regular identifier as used everywhere else here.
	 * @return bool
	 * @throws \InvalidArgumentException
	 */
	public function resourceExists($resourcePath) {
		if ($resourcePath == '') {
			throw new \InvalidArgumentException('Resource path cannot be empty');
		}
		$url = $this->baseUrl . ltrim($resourcePath, '/');
		try {
			$result = $this->executeDavRequest('HEAD', $url);
		} catch (\Sabre\Http\HttpException $exception) {
			return $exception->getHttpStatus() != 404;
		}
		// TODO check if other status codes may also indicate that the file is not present
		return $result['statusCode'] < 400;
	}


	/**
	 * Returns the complete URL to a file. This is not necessarily the publicly available URL!
	 *
	 * @param string $file The file object or its identifier
	 * @return string
	 */
	protected function getResourceUrl($file) {
		return $this->resourceBaseUrl . ltrim($file, '/');
	}

	/**
	 * Returns the public URL to a file. This does not contain a username or password, even if this is
	 * necessary to display the file.
	 *
	 * TODO make it optional to include the username/password
	 *
	 * @param string $identifier
	 * @return string
	 */
	public function getPublicUrl($identifier) {
			// as the storage is marked as public, we can simply use the public URL here.
		return $this->baseUrl . ltrim($identifier, '/');
	}

	/**
	 * Creates a (cryptographic) hash for a file.
	 *
	 * @param string $identifier The file identifier
	 * @param string $hashAlgorithm The hash algorithm to use
	 * @return string
	 * TODO switch parameter order?
	 */
	public function hash($identifier, $hashAlgorithm) {
		// TODO add unit test
		$fileCopy = $this->copyFileToTemporaryPath($identifier);

		switch ($hashAlgorithm) {
			case 'sha1':
				$hash = sha1_file($fileCopy);
				break;

			default:
				throw new \InvalidArgumentException('Unsupported hash algorithm ' . $hashAlgorithm);
		}

		unlink($fileCopy);

		return $hash;
	}

	/**
	 * Creates a new file and returns its identifier.
	 *
	 * @param string $fileName
	 * @param string $parentFolderIdentifier
	 * @return \TYPO3\CMS\Core\Resource\FileInterface
	 */
	public function createFile($fileName, $parentFolderIdentifier) {
		$fileIdentifier = $parentFolderIdentifier . $fileName;
		$fileUrl = $this->baseUrl . ltrim($fileIdentifier, '/');

		$this->executeDavRequest('PUT', $fileUrl, '');

		$this->removeCacheForPath($parentFolderIdentifier);

		return $fileIdentifier;
	}

	/**
	 * Returns the contents of a file. Beware that this requires to load the complete file into memory and also may
	 * require fetching the file from an external location. So this might be an expensive operation (both in terms of
	 * processing resources and money) for large files.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $fileIdentifier
	 * @return string The file contents
	 */
	public function getFileContents($fileIdentifier) {
		$fileUrl = $this->baseUrl . ltrim($fileIdentifier, '/');

		$result = $this->executeDavRequest('GET', $fileUrl);

		return $result['body'];
	}

	/**
	 * Sets the contents of a file to the specified value.
	 *
	 * @param string $fileIdentifier
	 * @param string $contents
	 * @return bool TRUE if setting the contents succeeded
	 * @throws \RuntimeException if the operation failed
	 */
	public function setFileContents($fileIdentifier, $contents) {
		// Apache returns a "204 no content" status after a successful put operation

		$fileUrl = $this->getResourceUrl($fileIdentifier);
		$result = $this->executeDavRequest('PUT', $fileUrl, $contents);

		$this->removeCacheForPath(dirname($fileIdentifier));

		// TODO check result
	}

	/**
	 * Adds a file from the local server hard disk to a given path in TYPO3s virtual file system.
	 *
	 * This assumes that the local file exists, so no further check is done here!
	 *
	 * @param string $localFilePath (within PATH_site)
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName optional, if not given original name is used
	 * @param boolean $removeOriginal if set the original file will be removed
	 *                                after successful operation
	 * @return string the identifier of the new file
	 */
	public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = TRUE) {
		$fileIdentifier = $targetFolderIdentifier . $newFileName;
		$fileUrl = $this->baseUrl . ltrim($fileIdentifier);

		$fileHandle = fopen($localFilePath, 'r');
		if (!is_resource($fileHandle)) {
			throw new \RuntimeException('Could not open handle for ' . $localFilePath, 1325959310);
		}
		$result = $this->executeDavRequest('PUT', $fileUrl, $fileHandle);

		// TODO check result

		$this->removeCacheForPath($targetFolderIdentifier);

		return $fileIdentifier;
	}

	/**
	 * Checks if a file exists.
	 *
	 * @param string $identifier
	 * @return bool
	 */
	public function fileExists($identifier) {
		return substr($identifier, -1) !== '/' && $this->resourceExists($identifier);
	}

	/**
	 * Checks if a file inside a storage folder exists.
	 *
	 * @param string $fileName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function fileExistsInFolder($fileName, $folderIdentifier) {
		// TODO add unit test
		$fileIdentifier = $folderIdentifier . $fileName;

		return $this->fileExists($fileIdentifier);
	}

	/**
	 * Returns a (local copy of) a file for processing it. When changing the file, you have to take care of replacing the
	 * current version yourself!
	 *
	 * @param string $fileIdentifier
	 * @param bool $writable Set this to FALSE if you only need the file for read operations. This might speed up things, e.g. by using a cached local version. Never modify the file if you have set this flag!
	 * @return string The path to the file on the local disk
	 */
	public function getFileForLocalProcessing($fileIdentifier, $writable = TRUE) {
		return $this->copyFileToTemporaryPath($fileIdentifier);
	}

	/**
	 * Renames a file
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface $file
	 * @param string $newName
	 * @return string The new identifier of the file
	 */
	public function renameFile($fileIdentifier, $newName) {
		// TODO add unit test
		// Renaming works by invoking the MOVE method on the source URL and providing the new destination in the
		// "Destination:" HTTP header.
		$sourcePath = $fileIdentifier;
		$targetPath = dirname($fileIdentifier) . '/' . $newName;

		$this->executeMoveRequest($sourcePath, $targetPath);

		$this->removeCacheForPath(dirname($fileIdentifier));

		return $targetPath;
	}

	/**
	 * Replaces the contents (and file-specific metadata) of a file object with a local file.
	 *
	 * @param string $fileIdentifier
	 * @param string $localFilePath
	 * @return bool
	 * @throws \RuntimeException
	 */
	public function replaceFile($fileIdentifier, $localFilePath) {
		$fileUrl = $this->getResourceUrl($fileIdentifier);
		$fileHandle = fopen($localFilePath, 'r');
		if (!is_resource($fileHandle)) {
			throw new \RuntimeException('Could not open handle for ' . $localFilePath, 1325959311);
		}

		$this->removeCacheForPath(dirname($fileIdentifier));

		$this->executeDavRequest('PUT', $fileUrl, $fileHandle);
	}

	/**
	 * Returns information about a file for a given file identifier.
	 *
	 * @param string $identifier The (relative) path to the file.
	 * @param array $propertiesToExtract The properties to get
	 * @return array
	 */
	public function getFileInfoByIdentifier($identifier, array $propertiesToExtract = array()) {
		assert($identifier[0] === '/', 'Identifier must start with a slash, got ' . $identifier);

		return $this->getFrontend()->getFileInfo($identifier);
	}

	/**
	 * Returns the cache identifier for a given path.
	 *
	 * @param string $path
	 * @return string
	 */
	protected function getCacheIdentifierForPath($path) {
		return sha1($this->storageUid . ':' . trim($path, '/') . '/');
	}

	/**
	 * Flushes the cache for a given path inside this storage.
	 *
	 * @param $path
	 * @return void
	 * @deprecated this should be moved to WebDavFrontend
	 */
	protected function removeCacheForPath($path) {
		$this->getCache()->remove($this->getCacheIdentifierForPath($path));
	}

	/**
	 * Copies a file to a temporary path and returns that path. You have to take care of removing the temporary file yourself!
	 *
	 * @param string $fileIdentifier
	 * @return string The temporary path
	 */
	public function copyFileToTemporaryPath($fileIdentifier) {
		$temporaryPath = GeneralUtility::tempnam('vfs-tempfile-');
		$fileUrl = $this->getResourceUrl($fileIdentifier);

		$fileHandle = fopen($temporaryPath, 'w');
		$fileUrl = $this->encodeUrl($fileUrl);
		$this->davClient->readUrlToHandle($fileUrl, $fileHandle);

		// the handle is not closed by readUrlToHandle()!
		fclose($fileHandle);

		return $temporaryPath;
	}

	/**
	 * Moves a file *within* the current storage.
	 * Note that this is only about an intra-storage move action, where a file is just
	 * moved to another folder in the same storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName
	 *
	 * @return string
	 * @throws FileOperationErrorException
	 */
	public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName) {
		$newPath = $targetFolderIdentifier . $newFileName;

		try {
			$result = $this->executeMoveRequest($fileIdentifier, $newPath);
		} catch (DAV\Exception $e) {
			// TODO insert correct exception here
			throw new FileOperationErrorException('Moving file ' . $fileIdentifier
				. ' to ' . $newPath . ' failed.', 1325848030);
		}
		// TODO check if there are some return codes that signalize an error, but do not throw an exception
		// status codes: 204: file was overwritten; 201: file was created;

		return $newPath;
	}

	/**
	 * Copies a file *within* the current storage.
	 * Note that this is only about an intra-storage copy action, where a file is just
	 * copied to another folder in the same storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $fileName
	 *
	 * @return string the Identifier of the new file
	 * @throws FileOperationErrorException
	 */
	public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName) {
		$oldFileUrl = $this->getResourceUrl($fileIdentifier);
		$newFileUrl = $this->getResourceUrl($targetFolderIdentifier) . $fileName;
		$newFileIdentifier = $targetFolderIdentifier . $fileName;

		try {
				// force overwriting the file (header Overwrite: T) because the Storage already handled possible conflicts
				// for us
			$result = $this->executeDavRequest('COPY', $oldFileUrl, NULL, array('Destination' => $newFileUrl, 'Overwrite' => 'T'));
		} catch (DAV\Exception $e) {
			// TODO insert correct exception here
			throw new FileOperationErrorException('Copying file ' . $fileIdentifier . ' to '
				. $newFileIdentifier . ' failed.', 1325848030);
		}
		// TODO check if there are some return codes that signalize an error, but do not throw an exception
		// status codes: 204: file was overwritten; 201: file was created;

		return $newFileIdentifier;
	}

	/**
	 * Folder equivalent to moveFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 *
	 * @return array All files which are affected, map of old => new file identifiers
	 * @throws FileOperationErrorException
	 */
	public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {
		$newFolderIdentifier = $targetFolderIdentifier . $newFolderName . '/';

		try {
			$result = $this->executeMoveRequest($sourceFolderIdentifier, $newFolderIdentifier);
		} catch (DAV\Exception $e) {
			// TODO insert correct exception here
			throw new FileOperationErrorException('Moving folder ' . $sourceFolderIdentifier
				. ' to ' . $newFolderIdentifier . ' failed: ' . $e->getMessage(), 1326135944);
		}
		// TODO check if there are some return codes that signalize an error, but do not throw an exception
		// status codes: 204: file was overwritten; 201: file was created;

		// TODO extract mapping of old to new identifiers from server response
	}

	/**
	 * Folder equivalent to copyFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 *
	 * @return boolean
	 * @throws FileOperationErrorException
	 */
	public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {
		$oldFolderUrl = $this->getResourceUrl($sourceFolderIdentifier);
		$newFolderUrl = $this->getResourceUrl($targetFolderIdentifier) . $newFolderName . '/';
		$newFolderIdentifier = $targetFolderIdentifier . $newFolderName . '/';

		try {
			$result = $this->executeDavRequest('COPY', $oldFolderUrl, NULL, array('Destination' => $newFolderUrl, 'Overwrite' => 'T'));
		} catch (DAV\Exception $e) {
			// TODO insert correct exception here
			throw new FileOperationErrorException('Moving folder ' . $sourceFolderIdentifier
				. ' to ' . $newFolderIdentifier . ' failed.', 1326135944);
		}
		// TODO check if there are some return codes that signalize an error, but do not throw an exception
		// status codes: 204: file was overwritten; 201: file was created;

		return $newFolderIdentifier;
	}

	/**
	 * Removes a file from this storage. This does not check if the file is still used or if it is a bad idea to delete
	 * it for some other reason - this has to be taken care of in the upper layers (e.g. the Storage)!
	 *
	 * @param string $fileIdentifier
	 * @return boolean TRUE if the operation succeeded
	 */
	public function deleteFile($fileIdentifier) {
		// TODO add unit tests
		$fileUrl = $this->baseUrl . ltrim($fileIdentifier, '/');

		$result = $this->executeDavRequest('DELETE', $fileUrl);

		// 204 is derived from the answer Apache gives - there might be other status codes that indicate success
		return ($result['statusCode'] == 204);
	}

	/**
	 * Returns the root level folder of the storage.
	 *
	 * @return \TYPO3\CMS\Core\Resource\Folder
	 */
	public function getRootLevelFolder() {
		return '/';
	}

	/**
	 * Returns the default folder new files should be put into.
	 *
	 * @return \TYPO3\CMS\Core\Resource\Folder
	 */
	public function getDefaultFolder() {
		return '/';
	}

	/**
	 * Creates a folder, within a parent folder.
	 * If no parent folder is given, a root level folder will be created
	 *
	 * @param string $newFolderName
	 * @param string $parentFolderIdentifier
	 * @param boolean $recursive If set, parent folders will be created if they don’t exist
	 * @return string The new folder’s identifier
	 */
	public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = FALSE) {
		// TODO test if recursive creation works
		// We add a slash to the path as some actions require a trailing slash on some servers.
		// Apache's mod_dav e.g. does not do it for this action, but it does not do harm either, so we add it anyways
		$folderPath = $parentFolderIdentifier . $newFolderName . '/';
		$folderUrl = $this->baseUrl . ltrim($folderPath, '/');

		$this->executeDavRequest('MKCOL', $folderUrl);

		$this->removeCacheForPath($parentFolderIdentifier);

		return $folderPath;
	}

	/**
	 * Checks if a folder exists
	 *
	 * @param string $identifier
	 * @return bool
	 */
	public function folderExists($identifier) {
		// TODO add unit test
		// TODO check if this test suffices to find out if the resource really is a folder - it might not do with some implementations
		$identifier = '/' . trim($identifier, '/') . '/';
		return $this->resourceExists($identifier);
	}

	/**
	 * Checks if a file inside a storage folder exists.
	 *
	 * @param string $folderName
	 * @param string $folderIdentifier
	 * @return bool
	 */
	public function folderExistsInFolder($folderName, $folderIdentifier) {
		$folderIdentifier = $folderIdentifier . $folderName . '/';
		return $this->resourceExists($folderIdentifier);
	}

	/**
	 * Checks if a given identifier is within a container, e.g. if a file or folder is within another folder.
	 * This can be used to check for webmounts.
	 *
	 * @param string $containerIdentifier
	 * @param string $content
	 * @return bool
	 */
	public function isWithin($containerIdentifier, $content) {
		$content = '/' . ltrim($content, '/');

		return GeneralUtility::isFirstPartOfStr($content, $containerIdentifier);
	}

	/**
	 * Removes a folder from this storage.
	 *
	 * @param string $folderIdentifier
	 * @param bool $deleteRecursively
	 * @return boolean
	 */
	public function deleteFolder($folderIdentifier, $deleteRecursively = FALSE) {
		$folderUrl = $this->getResourceUrl($folderIdentifier);

		$this->removeCacheForPath(dirname($folderIdentifier));

			// We don't need to specify a depth header when deleting (see sect. 9.6.1 of RFC #4718)
		$this->executeDavRequest('DELETE', $folderUrl, '', array());
	}

	/**
	 * Renames a folder in this storage.
	 *
	 * @param string $folderIdentifier
	 * @param string $newName The new folder name
	 * @return string The new identifier of the folder if the operation succeeds
	 * @throws \RuntimeException if renaming the folder failed
	 * @throws FileOperationErrorException
	 */
	public function renameFolder($folderIdentifier, $newName) {
		$targetPath = dirname($folderIdentifier) . '/' . $newName . '/';

		try {
			$result = $this->executeMoveRequest($folderIdentifier, $targetPath);
		} catch (DAV\Exception $e) {
			// TODO insert correct exception here
			throw new FileOperationErrorException('Renaming ' . $folderIdentifier . ' to '
				. $targetPath . ' failed.', 1325848030);
		}

		$this->removeCacheForPath(dirname($folderIdentifier));

		return $targetPath;
	}

	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param string $folderIdentifier
	 * @return bool TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty($folderIdentifier) {
		$folderContents = $this->frontend->propFind($folderIdentifier);

		return (count($folderContents) == 1);
	}

	/**
	 * Merges the capabilites set by the administrator in the storage configuration with the actual capabilities of
	 * this driver and returns the result.
	 *
	 * @param integer $capabilities
	 *
	 * @return integer
	 */
	public function mergeConfigurationCapabilities($capabilities) {
		$this->capabilities &= $capabilities;
		return $this->capabilities;
	}

	/**
	 * Returns the identifier of the folder the file resides in
	 *
	 * @param string $fileIdentifier
	 *
	 * @return string
	 */
	public function getParentFolderIdentifierOfIdentifier($fileIdentifier) {
		return dirname($fileIdentifier);
	}

	/**
	 * Returns the permissions of a file/folder as an array
	 * (keys r, w) of boolean flags
	 *
	 * @param string $identifier
	 * @return array
	 */
	public function getPermissions($identifier) {
		// TODO check this again
		return array('r' => TRUE, 'w' => TRUE);
	}

	/**
	 * Directly output the contents of the file to the output
	 * buffer. Should not take care of header files or flushing
	 * buffer before. Will be taken care of by the Storage.
	 *
	 * @param string $identifier
	 * @return void
	 */
	public function dumpFileContents($identifier) {
		// TODO: Implement dumpFileContents() method.
	}

	/**
	 * Returns information about a file.
	 *
	 * @param string $folderIdentifier
	 *
	 * @return array
	 * @throws FolderDoesNotExistException
	 */
	public function getFolderInfoByIdentifier($folderIdentifier) {
		if (!$this->folderExists($folderIdentifier)) {
			throw new FolderDoesNotExistException(
				'Folder ' . $folderIdentifier . ' does not exist.',
				1314516810
			);
		}
		return array(
			'identifier' => $folderIdentifier,
			'name' => basename($folderIdentifier),
			'storage' => $this->storageUid
		);
	}

	/**
	 * Returns a list of files inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array $filenameFilterCallbacks callbacks for filtering the items
	 * @param string $sort Property name used to sort the items.
	 *                     Among them may be: '' (empty, no sorting), name,
	 *                     fileext, size, tstamp and rw.
	 *                     If a driver does not support the given property, it
	 *                     should fall back to "name".
	 * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
	 *
	 * @return array of FileIdentifiers
	 */
	public function getFilesInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE,
	                                 array $filenameFilterCallbacks = array(), $sort = '', $sortRev = FALSE) {
		$files = $this->getFrontend()->listFiles($folderIdentifier);

		// TODO implement sorting

		$items = array();
		foreach ($files as $filename) {
			$items[$filename] = $folderIdentifier . $filename;
		}

		return $items;
	}

	/**
	 * Returns the number of files inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param boolean $recursive
	 * @param array $filenameFilterCallbacks callbacks for filtering the items
	 * @return integer Number of files in folder
	 */
	public function countFilesInFolder($folderIdentifier, $recursive = FALSE,
	                                   array $filenameFilterCallbacks = array()) {
		return count($this->getFrontend()->listFiles($folderIdentifier));
	}

	/**
	 * Returns a list of folders inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array $folderNameFilterCallbacks callbacks for filtering the items
	 * @param string $sort Property name used to sort the items.
	 *                     Among them may be: '' (empty, no sorting), name,
	 *                     fileext, size, tstamp and rw.
	 *                     If a driver does not support the given property, it
	 *                     should fall back to "name".
	 * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
	 *
	 * @return array of folder identifiers
	 */
	public function getFoldersInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE,
	                                   array $folderNameFilterCallbacks = array(), $sort = '', $sortRev = FALSE) {
		try {
            $folders = $this->getFrontend()->listFolders($folderIdentifier);

            // TODO implement sorting

            $items = array();
            foreach ($folders as $name) {
                $items[$name] = $folderIdentifier . $name . '/';
            }
            return $items;
        } catch (ClientHttpException $e) {
            $this->logger->critical(
                'Cannot list items in directory ' . $folderIdentifier . ' - Storage:' . $this->storageUid . ' - ' . $e->getMessage()
            );
            return array();
        } catch (ClientException $e) {
            $this->logger->critical(
                'Cannot list items in directory ' . $folderIdentifier . ' - Storage:' . $this->storageUid . ' - ' . $e->getMessage()
            );
            return array();
        }
    }

	/**
	 * Returns the number of folders inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param boolean $recursive
	 * @param array $folderNameFilterCallbacks callbacks for filtering the items
	 * @return integer Number of folders in folder
	 */
	public function countFoldersInFolder($folderIdentifier, $recursive = FALSE,
	                                     array $folderNameFilterCallbacks = array()) {
		return count($this->getFrontend()->listFolders($folderIdentifier));
	}

    /**
     * Returns the identifier of a file inside the folder
     *
     * @param string $fileName
     * @param string $folderIdentifier
     * @return string file identifier
     * @throws FileDoesNotExistException
     */
    public function getFileInFolder($fileName, $folderIdentifier)
    {
        $files = $this->getFilesInFolder($folderIdentifier);
        if (!array_key_exists($fileName, $files)) {
             throw new FileDoesNotExistException(
                 $fileName . 'does not exist in ' . $folderIdentifier,
                 1474629253
             );
        }
        return $files[$fileName];
    }

    /**
     * Returns the identifier of a folder inside the folder
     *
     * @param string $folderName The name of the target folder
     * @param string $folderIdentifier
     * @return string folder identifier
     * @throws FolderDoesNotExistException
     */
    public function getFolderInFolder($folderName, $folderIdentifier)
    {
        $folders = $this->getFoldersInFolder($folderIdentifier);
        if (!array_key_exists($folderName, $folders)) {
            throw new FolderDoesNotExistException(
                $folderName . 'does not exist in ' . $folderIdentifier,
                1474629253
            );
        }
        return $folders[$folderName];
    }
}
