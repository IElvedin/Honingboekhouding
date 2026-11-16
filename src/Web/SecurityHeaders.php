<?php

declare(strict_types=1);

namespace App\Web;

/**
 * HTTP security headers for the report page and the JSON endpoint.
 *
 * The page keeps its CSS and JavaScript inline. Instead of allowing all inline
 * code ('unsafe-inline'), the Content-Security-Policy lists the SHA-256 hash of
 * every inline <script> and <style> block. Only exactly that code may run:
 * anything injected into the page (e.g. via a member name) is blocked.
 *
 * The hashes are calculated from the HTML on every request, so editing the
 * page never leaves an outdated hash behind.
 */
class SecurityHeaders
{
    /** Headers for every response. */
    private const array COMMON = [
        // Never guess the content type: JSON stays JSON, text stays text
        'X-Content-Type-Options'       => 'nosniff',
        // Don't send the page address to other sites
        'Referrer-Policy'              => 'no-referrer',
        // Don't allow the page in a frame on another site (clickjacking); older browsers
        'X-Frame-Options'              => 'DENY',
        // The page uses no camera, microphone, location etc.
        'Permissions-Policy'           => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        // Isolate the page from windows opened by other sites
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    /**
     * Headers for the HTML page, with a CSP based on the inline code in $html.
     *
     * @return array<string, string>
     */
    public function forHtml(string $html): array
    {
        $policy = [
            "default-src 'none'",
            'script-src ' . $this->hashes($html, 'script'),
            'style-src ' . $this->hashes($html, 'style'),
            "connect-src 'self'",          // fetch('/api/report')
            "img-src 'self'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'none'",
        ];

        return self::COMMON + [
            'Content-Type'            => 'text/html; charset=utf-8',
            'Content-Security-Policy' => implode('; ', $policy),
        ];
    }

    /**
     * Headers for the JSON endpoint. The data contains member names (personal
     * data), so it must not be stored by the browser or a proxy.
     *
     * @return array<string, string>
     */
    public function forJson(): array
    {
        return self::COMMON + [
            'Content-Type'            => 'application/json; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control'           => 'no-store',
        ];
    }

    /**
     * The CSP hash sources of all inline <script> or <style> blocks, e.g.
     * "'sha256-abc...=' 'sha256-def...='". Returns "'none'" when there are none.
     */
    private function hashes(string $html, string $tag): string
    {
        // Only blocks without attributes (no src=...): exactly the inline code
        preg_match_all("#<$tag>(.*?)</$tag>#s", $html, $matches);

        $sources = array_map(
            fn (string $code): string => "'sha256-" . base64_encode(hash('sha256', $code, true)) . "'",
            $matches[1]
        );

        return $sources === [] ? "'none'" : implode(' ', array_unique($sources));
    }
}
