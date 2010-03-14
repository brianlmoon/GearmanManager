<?php

function fetch_url($job) {

    $workload = $job->workload();

    $result = file_get_contents($workload);

    return array(
        "log" => "Success",
        "result"=>$result
    );

}

?>