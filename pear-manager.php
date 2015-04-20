#!/usr/bin/env php
<?php

/**
 * Implements the worker portions of the PEAR Net_Gearman library
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   1997-Present Brian Moon
 * @package     GearmanManager
 *
 */

declare(ticks = 1);

/**
 * Uncomment and set to your prefix.
 */
//define("NET_GEARMAN_JOB_CLASS_PREFIX", "");

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'GearmanPearManager.php';

$mgr = new GearmanPearManager();
$mgr->run();