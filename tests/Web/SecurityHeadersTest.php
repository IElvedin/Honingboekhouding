<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Web\SecurityHeaders;
use PHPUnit\Framework\TestCase;

class SecurityHeadersTest extends TestCase
{
    private const string PAGE = __DIR__ . '/../../templates/report.html';

    private function hash(string $code): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $code, true)) . "'";
    }

    /** @return array<string, string> directive => value */
    private function csp(array $headers): array
    {
        $directives = [];
        foreach (explode(';', $headers['Content-Security-Policy']) as $part) {
            // "script-src 'sha256-...' 'sha256-...'" -> name "script-src", value "'sha256-...' 'sha256-...'"
            $words = array_pad(explode(' ', trim($part), 2), 2, '');
            $name  = $words[0];
            $value = $words[1];

            $directives[$name] = $value;
        }

        return $directives;
    }

    public function testCommonHeadersOnBothResponses(): void
    {
        $securityHeaders = new SecurityHeaders();

        foreach ([$securityHeaders->forHtml('<p>x</p>'), $securityHeaders->forJson()] as $headers) {
            $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
            $this->assertSame('no-referrer', $headers['Referrer-Policy']);
            $this->assertSame('DENY', $headers['X-Frame-Options']);
            $this->assertArrayHasKey('Permissions-Policy', $headers);
            $this->assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        }
    }

    public function testCspAllowsExactlyTheInlineCode(): void
    {
        $html = "<style>body{color:red}</style><script>alert(1)</script><script>console.log(2)</script>";

        $csp = $this->csp((new SecurityHeaders())->forHtml($html));

        $this->assertSame($this->hash('body{color:red}'), $csp['style-src']);
        $this->assertSame($this->hash('alert(1)') . ' ' . $this->hash('console.log(2)'), $csp['script-src']);
    }

    public function testCspNeverAllowsUnsafeInline(): void
    {
        $headers = (new SecurityHeaders())->forHtml(file_get_contents(self::PAGE));

        $this->assertStringNotContainsString('unsafe-inline', $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-eval', $headers['Content-Security-Policy']);
    }

    public function testCspForRealPageCoversAllInlineBlocks(): void
    {
        $html = file_get_contents(self::PAGE);
        $csp  = $this->csp((new SecurityHeaders())->forHtml($html));

        preg_match_all('#<script>(.*?)</script>#s', $html, $scripts);
        preg_match_all('#<style>(.*?)</style>#s', $html, $styles);

        // The page has an early theme script, the main script and one style block
        $this->assertCount(2, $scripts[1]);
        $this->assertCount(1, $styles[1]);

        foreach ($scripts[1] as $code) {
            $this->assertStringContainsString($this->hash($code), $csp['script-src']);
        }
        $this->assertStringContainsString($this->hash($styles[1][0]), $csp['style-src']);
    }

    public function testCspRestrictsEverythingElse(): void
    {
        $csp = $this->csp((new SecurityHeaders())->forHtml(file_get_contents(self::PAGE)));

        $this->assertSame("'none'", $csp['default-src']);
        $this->assertSame("'self'", $csp['connect-src']);   // only fetch('/api/report')
        $this->assertSame("'none'", $csp['frame-ancestors']);
        $this->assertSame("'none'", $csp['base-uri']);
        $this->assertSame("'none'", $csp['form-action']);
    }

    public function testPageWithoutInlineCodeAllowsNoScripts(): void
    {
        $csp = $this->csp((new SecurityHeaders())->forHtml('<p>geen code</p>'));

        $this->assertSame("'none'", $csp['script-src']);
        $this->assertSame("'none'", $csp['style-src']);
    }

    public function testJsonIsNotCachedAndCannotRunAnything(): void
    {
        $headers = (new SecurityHeaders())->forJson();

        $this->assertSame('application/json; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertSame("default-src 'none'; frame-ancestors 'none'", $headers['Content-Security-Policy']);
    }
}
