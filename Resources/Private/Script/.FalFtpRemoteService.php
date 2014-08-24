<?php

error_reporting(E_ALL);
set_error_handler('errorHandler');
set_exception_handler('exceptionHandler');
#output($_GET);
$encryptionKey = '###ENCRYPTION_KEY###';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$parameters = isset($_GET['parameters']) ? $_GET['parameters'] : array();

// Check encryption key.
if (isset($_GET['encryptionKey']) === FALSE || $_GET['encryptionKey'] !== $encryptionKey) {
	throw new \Exception('Request is not valid', 1408552214);
}

// Run service.
if (function_exists($action)) {
	call_user_func_array($action, $parameters);
} else {
	throw new \RuntimeException('Requested action "' . $action . '" dosn\'t exists.', 1408552215);
}

/**
 * Service function: Return the hash of a file.
 *
 * @param string $fileIdentifier
 * @param string $hashAlgorithm The hash algorithm to use
 * @return void
 */
function hashFile($fileIdentifier, $hashAlgorithm='') {

	$fileIdentifier = getAbsolutePath($fileIdentifier);

	if (@is_file($fileIdentifier) === FALSE) {
		throw new \Exception('File "' . $fileIdentifier . '" not exists.', 1408552217);
	}

	switch ($hashAlgorithm) {
		case 'sha1':
			$hash = sha1_file($fileIdentifier);
			break;
		case 'md5':
			$hash = md5_file($fileIdentifier);
			break;
		default:
			throw new \RuntimeException('Hash algorithm ' . $fileIdentifier . ' is not implemented.', 1408550582);
	}

	$response = array(
		'hash' => $hash,
	);

	output($response);
}

/**
 * Error handler.
 *
 * @param string $message
 * @return void
 */
function errorHandler($errno, $errstr, $errfile, $errline) {
	error("Error [$errno] $errstr on line $errline in file $errfile");
}

/**
 * Exception handler.
 *
 * @param \Exception $exception
 * @return void
 */
function exceptionHandler(\Exception $exception) {
	error((string) $exception);
}

/**
 * Send error message.
 *
 * @param string $message
 * @return void
 */
function error($message) {
	$response = array(
		'message' => $message,
	);
	output($response, FALSE);
}

/**
 * Flush json response.
 *
 * @param array $response
 * @return void
 */
function output($response, $noError = TRUE) {
	$response['result'] = $noError;
	$response = json_encode($response);
	header('Cache-Control: no-cache, must-revalidate');
	header('Content-Length: ' . strlen($response));
	header('Content-Type: application/json');
	echo $response;
	exit;
}

/**
 * Returns the absolute path of the FTP remote directory or file.
 *
 * @param string $identifier
 * @return array
 */
function getAbsolutePath($identifier) {
	return rtrim(__DIR__, '/') . '/' . ltrim($identifier, '/');
}

?>