<?php

declare(strict_types=1);

namespace App\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * Runs public/index.php as a separate process, like the web server does,
 * to test what a visitor gets back when something goes wrong.
 */
class PublicIndexTest extends TestCase
{
    private const string INDEX = __DIR__ . '/../../public/index.php';

    /**
     * @return array{stdout: string, stderr: string}
     */
    private function request(string $uri, string $database): array
    {
        $code = '$_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';'
            . '$_SERVER["REQUEST_METHOD"] = "GET";'
            . 'parse_str((string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_QUERY), $_GET);'
            . 'require ' . var_export(self::INDEX, true) . ';';

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HONING_DB' => $database] + getenv()
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testServerErrorShowsGeneralMessageWithoutDetails(): void
    {
        // A directory is not a database file: opening it fails with an internal error
        $result = $this->request('/api/report', sys_get_temp_dir());

        $body = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['fout' => 'Er ging iets mis op de server. Probeer het later opnieuw.'], $body);

        // No paths, SQL state or exception class for the visitor
        $this->assertStringNotContainsString(sys_get_temp_dir(), $result['stdout']);
        $this->assertStringNotContainsString('SQLSTATE', $result['stdout']);
        $this->assertStringNotContainsString('Exception', $result['stdout']);

        // ...but the details are in the server log
        $this->assertStringContainsString('PDOException', $result['stderr']);
    }

    public function testNormalRequestStillWorks(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'honing_');

        try {
            $body = json_decode($this->request('/api/report?type=top', $database)['stdout'], true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([], $body['top_volken']); // empty database, but no error
            $this->assertArrayNotHasKey('fout', $body);
        } finally {
            @unlink($database);
        }
    }
}
