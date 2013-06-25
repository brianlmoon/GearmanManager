#!/usr/bin/env php
<?php

/**
 * Set to your job name prefix.
 */
define("NET_GEARMAN_JOB_CLASS_PREFIX", "");

if(!class_exists("GearmanManager\Bridge\GearmanPearManager")){
    require __DIR__."/../src/GearmanManager/Bridge/GearmanPearManager.php";
}

declare(ticks = 1);

$gm = new GearmanManager\Bridge\GearmanPearManager();
