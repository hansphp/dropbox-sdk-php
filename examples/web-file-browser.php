<?php

require_once __DIR__.'/../test/strict.php';

$appInfoFile = __DIR__."/web-file-browser.app";

// NOTE: You should be using Composer's global autoloader.  But just so these examples
// work for people who don't have Composer, we'll use the library's "autoload.php".
require_once __DIR__.'/../lib/Dropbox/autoload.php';

use \Dropbox as dbx;

$request_path = init();

session_start();

if ($request_path === "/") {
    $dbxClient = getClient();

    if ($dbxClient === false) {
        header("Location: ".getPath("dropbox-auth-start"));
        exit;
    }

    $path = "/";
    if (isset($_GET['path'])) $path = $_GET['path'];

    if (isset($_GET['dl'])) {
        passFileToBrowser($dbxClient, $path);
    }
    else {
        $entry = $dbxClient->getMetadataWithChildren($path);

        if ($entry['is_dir']) {
            echo renderFolder($entry);
        }
        else {
            echo renderFile($entry);
        }
    }
}
else if ($request_path === "/dropbox-auth-start") {
    $authorizeUrl = getWebAuth()->start();
    header("Location: $authorizeUrl");
}
else if ($request_path === "/dropbox-auth-finish") {
    try {
        list($accessToken, $userId, $urlState) = getWebAuth()->finish($_GET);
        assert($urlState === null);
        unset($_SESSION['dropbox-auth-csrf-token']);
    }
    catch (dbx\WebAuthException_BadRequest $ex) {
        error_log("/dropbox-auth-finish: bad request: " . $ex->getMessage());
        // Respond with an HTTP 400 and display error page...
        exit;
    }
    catch (dbx\WebAuthException_BadState $ex) {
        // Auth session expired.  Restart the auth process.
        header("Location: ".getPath("dropbox-auth-start"));
        exit;
    }
    catch (dbx\WebAuthException_Csrf $ex) {
        error_log("/dropbox-auth-finish: CSRF mismatch: " . $ex->getMessage());
        // Respond with HTTP 403 and display error page...
        exit;
    }
    catch (dbx\WebAuthException_NotApproved $ex) {
        echo renderHtmlPage("Not Authorized?", "Why not, bro?");
        exit;
    }
    catch (dbx\WebAuthException_Provider $ex) {
        error_log("/dropbox-auth-finish: unknown error: " . $ex->getMessage());
        exit;
    }
    catch (dbx\Exception $ex) {
        error_log("/dropbox-auth-finish: error communicating with Dropbox API: " . $ex->getMessage());
        exit;
    }

    // NOTE: A real web app would store the access token in a database.
    $_SESSION['access-token'] = $accessToken;

    echo renderHtmlPage("Authorized!",
        "Auth complete, <a href='".htmlspecialchars(getPath(""))."'>click here</a> to browse.");
}
else if ($request_path === "/dropbox-auth-unlink") {
    // "Forget" the access token.
    unset($_SESSION['access-token']);
    echo renderHtmlPage("Unlinked.",
        "Go back <a href='".htmlspecialchars(getPath(""))."'>home</a>.");
}
else if ($request_path === "/upload") {
    if (empty($_FILES['file']['name'])) {
        echo renderHtmlPage("Error", "Please choose a file to upload");
        exit;
    }

    if (!empty($_FILES['file']['error'])) {
        echo renderHtmlPage("Error", "Error ".$_FILES['file']['error']." uploading file.  See <a href='http://php.net/manual/en/features.file-upload.errors.php'>the docs</a> for details");
        exit;
    }

    $dbxClient = getClient();

    $remoteDir = "/";
    if (isset($_POST['folder'])) $remoteDir = $_POST['folder'];

    $remotePath = rtrim($remoteDir, "/")."/".$_FILES['file']['name'];

    $fp = fopen($_FILES['file']['tmp_name'], "rb");
    $result = $dbxClient->uploadFile($remotePath, dbx\WriteMode::add(), $fp);
    fclose($fp);
    $str = print_r($result, TRUE);
    echo renderHtmlPage("Uploading File", "Result: <pre>$str</pre>");
}
else {
    echo renderHtmlPage("Bad URL", "No handler for $request_path");
    exit;
}

function renderFolder($entry)
{
    // TODO: Add a token to counter CSRF attacks.
    $upload_path = htmlspecialchars(getPath('upload'));
    $path = htmlspecialchars($entry['path']);
    $form = <<<HTML
        <form action='$upload_path' method='post' enctype='multipart/form-data'>
        <label for='file'>Upload file:</label> <input name='file' type='file'/>
        <input type='submit' value='Upload'/>
        <input name='folder' type='hidden' value='$path'/>
        </form>
HTML;

    $listing = '';
    foreach($entry['contents'] as $child) {
        $cp = $child['path'];
        $cn = basename($cp);
        if ($child['is_dir']) $cn .= '/';

        $cp = htmlspecialchars($cp);
        $link = getPath("?path=".htmlspecialchars($cp));
        $listing .= "<div><a style='text-decoration: none' href='$link'>$cn</a></div>";
    }

    return renderHtmlPage("Folder: $entry[path]", $form.$listing);
}

function getAppConfig()
{
    global $appInfoFile;

    try {
        $appInfo = dbx\AppInfo::loadFromJsonFile($appInfoFile);
    }
    catch (dbx\AppInfoLoadException $ex) {
        error_log("Unable to load \"$appInfoFile\": " . $ex->getMessage());
        die;
    }

    $clientIdentifier = "examples-web-file-browser";
    $userLocale = null;

    return array($appInfo, $clientIdentifier, $userLocale);
}

function getClient()
{
    if(!isset($_SESSION['access-token'])) {
        return false;
    }

    list($appInfo, $clientIdentifier, $userLocale) = getAppConfig();

    try {
        $accessToken = $_SESSION['access-token'];
        $dbxClient = new dbx\Client($accessToken, $clientIdentifier, $userLocale, $appInfo->getHost());
    }
    catch (Exception $e) {
        error_log("Error in getClient: ".$e->getMessage());
        return false;
    }

    return $dbxClient;
}

function getWebAuth()
{
    list($appInfo, $clientIdentifier, $userLocale) = getAppConfig();
    $redirectUri = getUrl("dropbox-auth-finish");
    $csrfTokenStore = new dbx\ArrayEntryStore($_SESSION, 'dropbox-auth-csrf-token');
    return new dbx\WebAuth($appInfo, $clientIdentifier, $redirectUri, $csrfTokenStore, $userLocale);
}

function renderFile($entry)
{
    $metadataStr = print_r($entry, TRUE);
    $path = htmlspecialchars($entry['path']);
    $body = <<<HTML
        <pre>$metadataStr</pre>
        <a href="/?path=$path&dl=true">Download this file</a>
HTML;

    return renderHtmlPage("File: ".$entry['path'], $body);
}

function passFileToBrowser(dbx\Client $dbxClient, $path)
{
    $fd = tmpfile();
    $metadata = $dbxClient->getFile($path, $fd);

    header("Content-type: $metadata[mime_type]");
    fseek($fd, 0);
    fpassthru($fd);
    fclose($fd);
}

function renderHtmlPage($title, $body)
{
    return <<<HTML
    <html>
        <head>
            <title>$title</title>
        </head>
        <body>
            <h1>$title</h1>
            $body
        </body>
    </html>
HTML;
}

function getUrl($relative_path)
{
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = "https";
    } else {
        $scheme = "http";
    }
    $host = $_SERVER['HTTP_HOST'];
    $path = getPath($relative_path);
    return $scheme."://".$host.$path;
}

function getPath($relative_path)
{
    if (PHP_SAPI == 'cli-server') {
        return "/".$relative_path;
    } else {
        return $_SERVER["SCRIPT_NAME"]."/".$relative_path;
    }
}

function init()
{
    global $argv;

    // They ran us as a command-line script.  Launch the PHP built-in web server.
    if (PHP_SAPI === 'cli') {
        launchBuiltInWebServer($argv);
        assert(false);
    }

    if (PHP_SAPI === 'cli-server') {
        // For when we're running under PHP's built-in web server, do the routing here.
        return $_SERVER['SCRIPT_NAME'];
    }
    else {
        // For when we're running under CGI or mod_php.
        if (isset($_SERVER['PATH_INFO'])) {
            return $_SERVER['PATH_INFO'];
        } else {
            return "/";
        }
    }
}

function launchBuiltInWebServer($argv)
{
    // The built-in web server is only available in PHP 5.4+.
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        fprintf(STDERR,
            "Unable to run example.  The version of PHP you used to run this script (".PHP_VERSION.")\n".
            "doesn't have a built-in web server.  You need PHP 5.4 or newer.\n".
            "\n".
            "You can still run this example if you have a web server that supports PHP 5.3.\n".
            "Copy the Dropbox PHP SDK into your web server's document path and access it there.\n");
        exit(2);
    }

    $php_file = $argv[0];
    if (count($argv) === 1) {
        $port = 5000;
    } else if (count($argv) === 2) {
        $port = intval($argv[1]);
    } else {
        fprintf(STDERR, "Usage: ".PHP_BINARY." [server-port]\n");
        exit(1);
    }

    $host = "localhost:$port";
    $cmd = escapeshellarg(PHP_BINARY)." -S ".$host." ".escapeshellarg($php_file);
    $descriptors = array(
        0 => array("pipe", "r"),  // Process' stdin.  We'll just close this right away.
        1 => STDOUT,              // Relay process' stdout to ours.
        2 => STDERR,              // Relay process' stderr to ours.
    );
    $proc = proc_open($cmd, $descriptors, $pipes);
    if ($proc === false) {
        fprintf(STDERR,
            "Unable to launch PHP's built-in web server.  Used command:\n".
            "   $cmd\n");
        exit(2);
    }
    fclose($pipes[0]);  // Close the process' stdin.
    $exitCode = proc_close($proc);  // Wait for process to exit.
    exit($exitCode);
}
