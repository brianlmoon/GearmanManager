#!/usr/bin/env php
<?php

// include composer autoloader if found
if (file_exists(__DIR__ . '/../../../autoload.php')) {
    require __DIR__ . '/../../../autoload.php';
}

if(!class_exists("GearmanManager\Bridge\GearmanPeclManager")) {
    require __DIR__."/../src/Bridge/GearmanPeclManager.php";
}

declare(ticks = 1);

$gm = new GearmanManager\Bridge\GearmanPeclManager();
