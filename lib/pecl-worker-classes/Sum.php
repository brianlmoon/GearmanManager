<?php

class Sum {

    private $cache = array();

    private $foo = 0;

    public function run($job, &$log) {

        $workload = $job->workload();

        if(empty($this->cache[$workload])){

            $dat = json_decode($workload, true);

            $sum = 0;

            foreach($dat as $d){
                $sum+=$d;
                sleep(1);
            }

            $this->cache[$workload] = $sum + 0;

        } else {

            $sum = $this->cache[$workload] + 0;

        }

        $log[] = "Answer: ".$sum;

        $this->foo = 1;

        return $sum;

    }

}

?>