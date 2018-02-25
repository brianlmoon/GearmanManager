<?php

class Reverse_String {

    public function run($job, &$log) {

        $workload = $job->workload();

        $result = strrev($workload);

        $log[] = "Answer: ".$result;

        return $result;

    }
}
