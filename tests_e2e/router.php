<?php

declare(strict_types=1);

/*
 * Router script of the PHP built-in web server in the end-to-end test: a file
 * that exists under the document root is served as it is (the compiled assets
 * in prod), every other request goes to the front controller (the assets
 * AssetMapper serves in dev).
 *
 * Without a router script, the built-in server of PHP 8.1 answers a missing
 * path with a file extension, such as /assets/Card/Card-1a2b3c.css, with 404
 * itself instead of passing it to index.php (PHP 8.5 passes it on).
 */

$documentRoot = $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ('/' !== $path && is_file($documentRoot.$path)) {
    return false;
}

// The Symfony runtime loads the application from the script being run.
$_SERVER['SCRIPT_FILENAME'] = $documentRoot.'/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';

require $documentRoot.'/index.php';
