<?php

class Fetch_Url {

    public function run($job, &$log) {

        $workload = $job->workload();

        $result = file_get_contents($workload);

        $log[] = "Success";

        return $result;

    }

}
