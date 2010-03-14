<?php

$gearman_config = array(

    "worker_dir" => "./workers",

    "max_run_time" => 3600,

    "do_all" => 3,

    "servers" => array(
        "127.0.0.1"
    ),

    "workers" => array(

        "reverse_string" => array(

            "count" => 3

        ),

    ),

);

?>