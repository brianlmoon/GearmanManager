<?php

class Net_Gearman_Job_Fetch_Url extends Net_Gearman_Job_Common {

    public function run($workload) {

        $result = file_get_contents($workload);

        GearmanPearManager::$LOG[] = "Success";

        return $result;

    }

}

?>