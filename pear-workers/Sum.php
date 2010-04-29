<?php

class Net_Gearman_Job_Sum extends Net_Gearman_Job_Common {

    public static $cache = array();

    public function run($workload) {

        $hash = md5(json_encode($workload));

        if(empty(self::$cache[$hash])){

            $sum = 0;

            foreach($workload as $d){
                $sum+=$d;
                sleep(1);
            }

            self::$cache[$hash] = $sum;

        } else {

            $sum = self::$cache[$hash];

        }

        GearmanPearManager::$LOG[] = "Answer: ".$sum;

        return $sum;

    }

}

?>