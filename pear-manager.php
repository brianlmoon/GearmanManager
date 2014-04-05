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

require dirname(__FILE__) . "/GearmanManager.php";
require dirname(__FILE__) . "/GearmanPearManager.php";
$mgr = new GearmanPearManager();
$mgr->run();