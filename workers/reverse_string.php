<?php

function reverse_string($job, &$log) {

    $workload = $job->workload();

    $result = strrev($workload);

    $log[] = "Success";

    return $result;

}

?>