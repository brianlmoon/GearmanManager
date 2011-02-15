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

        $f = $job->functionName();

        if(empty($objects[$f]) && !function_exists($f) && !class_exists($f)){

            if(!isset($this->functions[$f])){
                $this->log("Function $f is not a registered job name");
                return;
            }

            @include $this->functions[$f]["path"];

            if(class_exists($f) && method_exists($f, "run")){

                $this->log("Creating a $f object", GearmanManager::LOG_LEVEL_WORKER_INFO);
                $objects[$f] = new $f();

            } elseif(!function_exists($f)) {

                $this->log("Function $f not found");
                return;
            }

        }

        $this->log("($h) Starting Job: $f", GearmanManager::LOG_LEVEL_WORKER_INFO);

        $this->log("($h) Workload: $w", GearmanManager::LOG_LEVEL_DEBUG);

        $log = array();

        /**
         * Run the real function here
         */
        if(isset($objects[$f])){
            $result = $objects[$f]->run($job, $log);
        } else {
            $result = $f($job, $log);
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

        return $result;

    }

    /**
     * Validates the PECL compatible worker files/functions
     */
    protected function validate_lib_workers() {

        foreach($this->functions as $func => $props){
            @include $props["path"];
            if(!function_exists($func) &&
               (!class_exists($func) || !method_exists($func, "run"))){
                $this->log("Function $func not found in ".$props["path"]);
                posix_kill($this->parent_pid, SIGUSR2);
                exit(1);
            }
        }
    }

}

$mgr = new GearmanPeclManager();

?>
