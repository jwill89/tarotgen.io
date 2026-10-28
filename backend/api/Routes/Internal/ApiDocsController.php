<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Utility\ContentSecurityPolicy;

/**
 * The raw OpenAPI 3.1 spec (generated from the swagger-php attributes via
 * `composer docs`) and the Scalar reference UI that renders it. Both are public,
 * safe GETs and are deliberately NOT part of the documented API surface.
 */
class ApiDocsController extends AbstractController
{
    /**
     * Scalar runs on our origin, where a signed-in admin's cookies live, so the
     * bundle is pinned to one version with Subresource Integrity and the page gets
     * its own enforced CSP (og.php sets the site policy, and /api/* never passes
     * through it). To upgrade: bump the version, recompute the sha384 of the same
     * file, update both.
     */
    public const string SCALAR_BUNDLE = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.74.0/dist/browser/standalone.js';
    public const string SCALAR_SRI    = 'sha384-VXnKAz61Xwc3tbw1P+KKrUP7YbcsSl1Ik4is5UnogJyw9XEYZ5Ay/T1i0zPYtSZH';

    public function spec(Request $request, Response $response): Response|ResponseInterface
    {
        $path = dirname(__DIR__, 3) . '/openapi.json';
        $spec = is_file($path) ? file_get_contents($path) : false;

        if ($spec === false) {
            return $response->withJson(['error' => 'API specification has not been generated. Run: composer docs'], 500);
        }

        $response->getBody()->write($spec);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function reference(Request $request, Response $response): Response|ResponseInterface
    {
        $bundle = self::SCALAR_BUNDLE;
        $sri    = self::SCALAR_SRI;
        $response->getBody()->write(<<<HTML
            <!doctype html>
            <html>
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>TarotGen.io — API Reference</title>
              </head>
              <body>
                <script id="api-reference" data-url="/api/openapi.json"></script>
                <script src="{$bundle}" integrity="{$sri}" crossorigin="anonymous"></script>
              </body>
            </html>
            HTML);

        // Only that one script — plus a nonce, because Cloudflare injects its analytics
        // beacon and bot-detection script into this page too and stamps the header's
        // nonce on them (as for every other page; see ContentSecurityPolicy). Scalar
        // injects its own styles and loads its fonts from fonts.scalar.com. No
        // proxy.scalar.com: the API is same-origin.
        $nonce = ContentSecurityPolicy::nonce();
        $csp = "default-src 'none'; "
            . "script-src {$bundle} 'nonce-{$nonce}' https://static.cloudflareinsights.com; "
            . "style-src 'unsafe-inline'; font-src https://fonts.scalar.com data:; img-src 'self' data:; "
            . "connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', $csp);
    }
}
