<?php

date_default_timezone_set('UTC');
define("LOG_DEBUG_LEVEL", 10);
define("LOG_ERROR_LEVEL", 40);

if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . ".env"))
    die('Application configuration not found.');

// Parse dotenv file
$env = file(__DIR__ . DIRECTORY_SEPARATOR . ".env");
foreach ($env as $row) {
    $matches = array();

    if (preg_match("/^(?!#)([A-Za-z_]{1,})\=(.*?)$/si", $row, $matches)) {
        if (!putenv($matches[1] . "=" . trim($matches[2], "\""))) {
            die('Fatal error while parsing configuration, please check configuration file.');
        }
    }
}

switch (getenv("ENVIRONMENT")) {
    case "production":
        error_reporting(NULL);
        ini_set("display_errors", "0");
        break;
    default:
        error_reporting(E_ALL);
        ini_set("display_errors", "1");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(getenv('MYSQL_HOSTNAME'), getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), getenv('MYSQL_DATABASE'));
} catch (Exception $e) {
    die('Database connection not established, please check application configuration');
}

include __DIR__ . DIRECTORY_SEPARATOR . "translations.php";

function __($str, $language_code)
{
    global $lang;

    return isset($lang[$language_code]) && isset($lang[$language_code][$str]) ? $lang[$language_code][$str] : $str;
}


function log_event($s = "", $level = LOG_DEBUG_LEVEL)
{
    if (getenv("ENVIRONMENT") !== 'production') {
        echo "[" . date("Y-m-d H:i:s") . "] " . $s . "\n";
    }

    if ($level == LOG_ERROR_LEVEL) {
        file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . "logs.log", date("Y-m-d H:i:s") . " " . $s . "\n", FILE_APPEND);
    }
}