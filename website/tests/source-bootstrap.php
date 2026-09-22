<?php

/*
| PHPUnit bootstrap for testing a source checkout against a separate Composer
| vendor directory (the production server keeps deploy sources outside the
| live app). The source namespaces are prepended so the checkout wins over the
| class map compiled for the live installation.
*/
$autoload = getenv('SERVERNET_TEST_VENDOR_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

if (! is_file($autoload)) {
    throw new RuntimeException('Composer autoload file was not found: '.$autoload);
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
$loader->setClassMapAuthoritative(false);
$loader->setPsr4('App\\', [dirname(__DIR__).'/app/'], true);
$loader->setPsr4('Tests\\', [__DIR__.'/'], true);

// Composer's optimized production map wins before PSR-4. Redirect every App
// class that exists in this checkout, otherwise tests silently exercise live
// code instead of the commit under test.
$sourceMap = [];
foreach ($loader->getClassMap() as $class => $path) {
    if (! str_starts_with($class, 'App\\')) {
        continue;
    }
    $candidate = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($candidate)) {
        $sourceMap[$class] = $candidate;
    }
}
$loader->addClassMap($sourceMap);
$loader->register(true);

require_once __DIR__.'/TestCase.php';
