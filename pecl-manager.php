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
            if (!in_array($w, $this->ignore_workers)) {
                $this->log("Adding job $w", GearmanManager::LOG_LEVEL_WORKER_INFO);
                $thisWorker->addFunction($w, array($this, "do_job"), $this);
            } else {
                $this->log("Skipping job $w", GearmanManager::LOG_LEVEL_INFO);
            }
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
        $q = $this->prefix . $f;

        if(empty($objects[$q]) && !function_exists($q) && !class_exists($q)){

            include $this->worker_dir."/$f.php";

            if(class_exists($q) && method_exists($q, "run")){

                $this->log("Creating a $q object", GearmanManager::LOG_LEVEL_WORKER_INFO);
                $objects[$q] = new $q();
                $this->log("Created a $q object", GearmanManager::LOG_LEVEL_WORKER_INFO);

            } elseif(!function_exists($q)) {

                $this->log("Function $q not found");
                return;
            }

        }

        $this->log("($h) Starting Job: $f ($q)", GearmanManager::LOG_LEVEL_WORKER_INFO);

        $this->log("($h) Workload: $w", GearmanManager::LOG_LEVEL_DEBUG);

        $log = array();

        /**
         * Run the real function here
         */
        if(isset($objects[$q])){
            $result = $objects[$q]->run($job, $log);
        } else {
            $result = $q($job, $log);
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

        /**
         * Check if we should restart the worker after each job is complete
         */
        if ($this->restart_each) {
            $this->stop_work = true;
        }

        return $result;

    }

    /**
     * Validates the PECL compatible worker files/functions
     */
    protected function validate_lib_workers($worker_files) {

        foreach($worker_files as $file){
            $function = substr(basename($file), 0, -4);
            if (in_array($function, $this->ignore_workers)) {
                continue;
            }
            include $file;
            if(!function_exists($function) &&
               (!class_exists($function) || !method_exists($function, "run"))){
                $this->log("Function $function not found in $file");
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
        }
    }

}

$mgr = new GearmanPeclManager();

?>