#!/usr/bin/env php
<?php

/**
 * Implements the worker portions of the pecl/gearman library
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   1997-Present Brian Moon
 * @package     GearmanManager
 *
 */

declare(ticks = 1);

require dirname(__FILE__)."/GearmanManager.php";

/**
 * Implements the worker portions of the pecl/gearman library
 */
class GearmanPeclManager extends GearmanManager {

    /**
     * Starts a worker for the PECL library
     *
     * @param   array   $worker_list    List of worker functions to add
     * @return  void
     *
     */
    protected function start_lib_worker($worker_list) {

        $thisWorker = new GearmanWorker();

        $thisWorker->addOptions(GEARMAN_WORKER_NON_BLOCKING);

        $thisWorker->setTimeout(5000);

        foreach($this->servers as $s){
            $this->log("Adding server $s", GearmanManager::LOG_LEVEL_WORKER_INFO);
            $thisWorker->addServers($s);
        }

        foreach($worker_list as $w){
            $this->log("Adding job $w", GearmanManager::LOG_LEVEL_WORKER_INFO);
            $thisWorker->addFunction($w, array($this, "do_job"), $this);
        }

        $start = time();

        while(!$this->stop_work){

            if(@$thisWorker->work() ||
               $thisWorker->returnCode() == GEARMAN_IO_WAIT ||
               $thisWorker->returnCode() == GEARMAN_NO_JOBS) {

                if ($thisWorker->returnCode() == GEARMAN_SUCCESS) continue;

                if (!@$thisWorker->wait()){
                    if ($thisWorker->returnCode() == GEARMAN_NO_ACTIVE_FDS){
                        sleep(5);
                    }
                }

            }

            /**
             * Check the running time of the current child. If it has
             * been too long, stop working.
             */
            if($this->max_run_time > 0 && time() - $start > $this->max_run_time) {
                $this->log("Been running too long, exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

            if(!empty($this->config["max_runs_per_worker"]) && $this->job_execution_count >= $this->config["max_runs_per_worker"]) {
                $this->log("Ran $this->job_execution_count jobs which is over the maximum({$this->config['max_runs_per_worker']}), exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

        }

        $thisWorker->unregisterAll();


    }

    /**
     * Wrapper function handler for all registered functions
     * This allows us to do some nice logging when jobs are started/finished
     */
    public function do_job($job) {

        static $objects;

        if($objects===null) $objects = array();

        $w = $job->workload();

        $h = $job->handle();

        $job_name = $job->functionName();

        if($this->prefix){
            $func = $this->prefix.$job_name;
        } else {
            $func = $job_name;
        }

        if(empty($objects[$job_name]) && !function_exists($func) && !class_exists($func)){

            if(!isset($this->functions[$job_name])){
                $this->log("Function $func is not a registered job name");
                return;
            }

            require_once $this->functions[$job_name]["path"];

            if(class_exists($func) && method_exists($func, "run")){

                $this->log("Creating a $func object", GearmanManager::LOG_LEVEL_WORKER_INFO);
                $objects[$job_name] = new $func();

            } elseif(!function_exists($func)) {

                $this->log("Function $func not found");
                return;
            }

        }

        $this->log("($h) Starting Job: $job_name", GearmanManager::LOG_LEVEL_WORKER_INFO);

        $this->log("($h) Workload: $w", GearmanManager::LOG_LEVEL_DEBUG);

        $log = array();

        /**
         * Run the real function here
         */
        if(isset($objects[$job_name])){
            $this->log("($h) Calling object for $job_name.", GearmanManager::LOG_LEVEL_DEBUG);
            $result = $objects[$job_name]->run($job, $log);
        } elseif(function_exists($func)) {
            $this->log("($h) Calling function for $job_name.", GearmanManager::LOG_LEVEL_DEBUG);
            $result = $func($job, $log);
        } else {
            $this->log("($h) FAILED to find a function or class for $job_name.", GearmanManager::LOG_LEVEL_INFO);
        }

        if(!empty($log)){
            foreach($log as $l){

                if(!is_scalar($l)){
                    $l = explode("\n", trim(print_r($l, true)));
                } elseif(strlen($l) > 256){
                    $l = substr($l, 0, 256)."...(truncated)";
                }

                if(is_array($l)){
                    foreach($l as $ln){
                        $this->log("($h) $ln", GearmanManager::LOG_LEVEL_WORKER_INFO);
                    }
                } else {
                    $this->log("($h) $l", GearmanManager::LOG_LEVEL_WORKER_INFO);
                }

            }
        }

        $result_log = $result;

        if(!is_scalar($result_log)){
            $result_log = explode("\n", trim(print_r($result_log, true)));
        } elseif(strlen($result_log) > 256){
            $result_log = substr($result_log, 0, 256)."...(truncated)";
        }

        if(is_array($result_log)){
            foreach($result_log as $ln){
                $this->log("($h) $ln", GearmanManager::LOG_LEVEL_DEBUG);
            }
        } else {
            $this->log("($h) $result_log", GearmanManager::LOG_LEVEL_DEBUG);
        }

        /**
         * Workaround for PECL bug #17114
         * http://pecl.php.net/bugs/bug.php?id=17114
         */
        $type = gettype($result);
        settype($result, $type);


        $this->job_execution_count++;

        return $result;

    }

    /**
     * Validates the PECL compatible worker files/functions
     */
    protected function validate_lib_workers() {

        foreach($this->functions as $func => $props){
            require_once $props["path"];
            $real_func = $this->prefix.$func;
            if(!function_exists($real_func) &&
               (!class_exists($real_func) || !method_exists($real_func, "run"))){
                $this->log("Function $real_func not found in ".$props["path"]);
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
        }

    }

}

$mgr = new GearmanPeclManager();

?>

