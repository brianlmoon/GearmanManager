<?php

class Avg {

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

            $avg = $sum / count($dat);

            $this->cache[$workload] = $avg + 0;

        } else {

            $avg = $this->cache[$workload] + 0;

        }

        $log[] = "Answer: ".$avg;

        $this->foo = 1;

        return $avg;

    }

}

?>