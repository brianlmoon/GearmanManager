<?php

use GearmanManager\Bridge\GearmanPearManager;

class Reverse_String extends Net_Gearman_Job_Common {

    public function run($workload) {

        $result = strrev($workload);

        GearmanPearManager::$LOG[] = "Success";

        return $result;

    }

}

?>