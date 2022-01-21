<?php

use Composer\Autoload\ClassLoader;

$loader = new ClassLoader();
$loader->addPsr4('MyCustomNamespace\\', __DIR__);
$loader->addPsr4('MyCustomNamespace\\', __DIR__ . "/src");
$loader->register();
