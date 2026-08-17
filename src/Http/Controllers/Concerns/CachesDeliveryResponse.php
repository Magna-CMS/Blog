<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adds validation + freshness caching to a delivery JSON response: a strong ETag
 * derived from the body plus a public Cache-Control max-age. When the client
 * sends a matching If-None-Match, a 304 (empty body) is returned instead — so
 * unchanged content is never re-sent, and a CDN can cache it briefly.
 */
trait CachesDeliveryResponse
{
    private function cached(Request $request, JsonResponse $response, int $maxAge = 60): JsonResponse
    {
        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge($maxAge);

        // Turns the response into a 304 (dropping the body) when the ETag matches.
        $response->isNotModified($request);

        return $response;
    }
}
