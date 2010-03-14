<?php

function reverse_string($job) {

    $workload = $job->workload();

    $result = strrev($workload);

    return array(
        "log" => "Success",
        "result"=>$result
    );

}

?>