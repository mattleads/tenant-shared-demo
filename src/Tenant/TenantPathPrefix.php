<?php

namespace App\Tenant;

use Symfony\Component\HttpFoundation\Request;

/**
 * A request for `/tenant_<uuid>/…`. The prefix becomes the request's base path, the way Symfony serves an app from a
 * sub-directory: routing sees the path without it, and every URL the router generates keeps it.
 *
 * A dedicated deployment's ingress can still strip the prefix, so its requests arrive without one.
 */
final class TenantPathPrefix
{
    /** The request attribute holding the uuid of the prefix. */
    public const string ATTRIBUTE = '_tenant_prefix';

    private const string PATTERN = '#^/tenant_([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})(?:/|$)#i';

    /** The same request based on its prefix, or null when the path has none. */
    public static function rebase(Request $request): ?Request
    {
        if (!preg_match(self::PATTERN, rawurldecode($request->getPathInfo()), $match) || $request->getBaseUrl() !== '') {
            return null;
        }
        $uuid = strtolower($match[1]);
        $front = '/tenant_'.$uuid.'/index.php';
        $server = $request->server->all();
        $server['SCRIPT_NAME'] = $server['PHP_SELF'] = $front;
        $server['SCRIPT_FILENAME'] = ($server['SCRIPT_FILENAME'] ?? '') !== '' ? $server['SCRIPT_FILENAME'] : 'index.php';

        $rebased = $request->duplicate(server: $server);
        $rebased->attributes->set(self::ATTRIBUTE, $uuid);

        return $rebased;
    }
}
