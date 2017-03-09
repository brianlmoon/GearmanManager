#!/usr/bin/env php
<?php

if(!class_exists("GearmanManager\Bridge\GearmanPeclManager")) {
    require __DIR__."/../src/Bridge/GearmanPeclManager.php";
}

$gm = new GearmanManager\Bridge\GearmanPeclManager();
