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
$loader->setPsr4('App\\', [dirname(__DIR__).'/app/'], true);
$loader->setPsr4('Tests\\', [__DIR__.'/'], true);

