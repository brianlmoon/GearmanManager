<?php

function sum($job, &$log) {

    $workload = $job->workload();

    $sum = array_sum($workload);

    $log[] = "Answer: ".$sum;

    return $sum;
}
