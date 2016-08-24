<?php

use Symfony\Component\ClassLoader\ApcClassLoader;
use Symfony\Component\Debug\Debug;
use Symfony\Component\HttpFoundation\Request;

$appDir = __DIR__ . '/../app';

$loader = require_once $appDir . '/bootstrap.php.cache';

// Enable APC for autoloading to improve performance.
// You should change the ApcClassLoader first argument to a unique prefix
// in order to prevent cache key conflicts with other applications
// also using APC.
/*
$apcLoader = new ApcClassLoader(sha1(__FILE__), $loader);
$loader->unregister();
$apcLoader->register(true);
*/

require_once $appDir . '/AppKernel.php';
//require_once __DIR__.'/../app/AppCache.php';

$request = Request::createFromGlobals();
$env = 'prod';
$remoteIp = $request->getClientIp();
$adminIps = [
    '109.81.209.64',
    '94.112.251.18',
];
if (in_array($remoteIp, $adminIps)) {
    $env = 'dev';
    Debug::enable();
}
$kernel = new AppKernel($env, false);
$kernel->loadClassCache();
//$kernel = new AppCache($kernel);

// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
//Request::enableHttpMethodParameterOverride();

$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
