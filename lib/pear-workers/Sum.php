<?php

use GearmanManager\Bridge\GearmanPearManager;

class Sum extends Net_Gearman_Job_Common {

    public static $cache = array();

    public function run($workload) {

        $sum = array_sum($workload);

        GearmanPearManager::$LOG[] = "Answer: ".$sum;

        return $sum;

    }

}
