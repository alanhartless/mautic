<?php

/*
 * @copyright   20169 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class TrustMiddleware implements HttpKernelInterface, PrioritizedMiddlewareInterface
{
    use ConfigAwareTrait;

    const PRIORITY = 0;

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true): void
    {
        $config = $this->getConfig();

        if (!empty($config['trusted_proxies'])) {
            Request::setTrustedProxies($config['trusted_proxies'], Request::getTrustedHeaderSet());
        }

        if (!empty($config['trusted_hosts'])) {
            Request::setTrustedHosts($config['trusted_hosts']);
        }
    }
}
