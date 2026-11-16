<?php

declare(strict_types=1);

/**
 * Router for the built-in web server:
 *
 *   php -S localhost:8000 -t public
 *
 *   /             -> the report page (templates/report.html)
 *   /api/report   -> JSON (see src/Web/ApiController.php)
 *
 * The page lives outside public/ on purpose: php -S serves files in public/
 * directly, without passing through this script and so without security headers.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Report\ReportService;
use App\Web\ApiController;
use App\Web\SecurityHeaders;

$path    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$headers = new SecurityHeaders();

$send = function (array $headerList): void {
    foreach ($headerList as $name => $value) {
        header("$name: $value");
    }
};

if ($path === '/' || $path === '/index.html') {
    $html = file_get_contents(__DIR__ . '/../templates/report.html');

    $send($headers->forHtml($html));
    echo $html;
    return;
}

$send($headers->forJson());

try {
    $controller = new ApiController(
        // Same override as the CLI, so tests can point the web endpoint at a temporary database
        new ReportService(new Database(getenv('HONING_DB') ?: __DIR__ . '/../var/honing.sqlite')),
        (int) date('Y'),
    );

    $response = $controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET);
} catch (Throwable $e) {
    // Unexpected error (e.g. the database cannot be opened). The details (paths, SQL)
    // only go to the server log; the visitor gets a general message.
    error_log((string) $e);
    $response = ['status' => 500, 'body' => ['fout' => 'Er ging iets mis op de server. Probeer het later opnieuw.']];
}

http_response_code($response['status']);
echo json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
