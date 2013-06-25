<?php

function fetch_url($job, &$log) {

    $workload = $job->workload();

    $result = file_get_contents($workload);

    $log[] = "Success";

    return $result;

}

?>