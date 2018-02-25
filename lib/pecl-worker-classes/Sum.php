<?php

class Sum {

    public function run($job, &$log) {

        $workload = $job->workload();

        $sum = array_sum($workload);

        $log[] = "Answer: ".$sum;

        return $sum;

    }

}
