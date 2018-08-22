#!/usr/bin/env php
<?php

/**
 * Set to your job name prefix.
 */
define("NET_GEARMAN_JOB_CLASS_PREFIX", "");

if (file_exists(__DIR__ . '/../../../autoload.php')) {
    require __DIR__ . '/../../../autoload.php';
}

if(!class_exists("GearmanManager\Bridge\GearmanPearManager")) {
    require __DIR__."/../src/Bridge/GearmanPearManager.php";
}

declare(ticks = 1);

$gm = new GearmanManager\Bridge\GearmanPearManager();
