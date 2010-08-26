#!/usr/bin/env php
<?php

/**
 * Implements the worker portions of the PEAR Net_Gearman library
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   1997-Present Brian Moon
 * @package     GearmanManager
 *
 */

declare(ticks = 1);

/**
 * Uncomment and set to your prefix.
 */
//define("NET_GEARMAN_JOB_CLASS_PREFIX", "");


require dirname(__FILE__)."/GearmanManager.php";

/**
 * Implements the worker portions of the PEAR Net_Gearman library
 */
class GearmanPearManager extends GearmanManager {

    public static $LOG = array();

    private $start_time;

    /**
     * Starts a worker for the PEAR library
     *
     * @param   array   $worker_list    List of worker functions to add
     * @return  void
     *
     */
    protected function start_lib_worker($worker_list) {

        /**
         * Require PEAR Net_Gearman libs
         */
        define('NET_GEARMAN_JOB_PATH', $this->worker_dir);
        require "Net/Gearman/Worker.php";

        $worker = new Net_Gearman_Worker($this->servers);

        foreach($worker_list as $w){
            $this->log("Adding job $w", GearmanManager::LOG_LEVEL_WORKER_INFO);
            $worker->addAbility($w);
        }

        $worker->attachCallback(array($this, 'job_start'), Net_Gearman_Worker::JOB_START);
        $worker->attachCallback(array($this, 'job_complete'), Net_Gearman_Worker::JOB_COMPLETE);
        $worker->attachCallback(array($this, 'job_fail'), Net_Gearman_Worker::JOB_FAIL);

        $this->start_time = time();

        $worker->beginWork(array($this, "monitor"));

    }

    /**
     * Monitor call back for worker. Return false to stop worker
     *
     * @param   bool    $idle       If true the worker was idle
     * @param   int     $lastJob    The time the last job was run
     * @return  bool
     *
     */
    public function monitor($idle, $lastJob) {

        if($this->max_run_time > 0 && time() - $this->start_time > $this->max_run_time) {
            $this->log("Been running too long, exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);
            $this->stop_work = true;
        }

        $time = time() - $lastJob;

        $this->log("Worker's last job $time seconds ago", GearmanManager::LOG_LEVEL_CRAZY);

        return $this->stop_work;
    }

    /**
     * Call back for when jobs are started
     */
    public function job_start($handle, $job, $args) {
        $this->log("($handle) Starting Job: $job", GearmanManager::LOG_LEVEL_WORKER_INFO);
        $this->log("($handle) Workload: ".json_encode($args), GearmanManager::LOG_LEVEL_DEBUG);
        self::$LOG = array();
    }


    /**
     * Call back for when jobs are completed
     */
    public function job_complete($handle, $job, $result) {

        $this->log("($handle) Completed Job: $job", GearmanManager::LOG_LEVEL_WORKER_INFO);

        $this->log_result($handle, $result);
    }

    /**
     * Call back for when jobs fail
     */
    public function job_fail($handle, $job, $result) {

        $this->log("($handle) Failed Job: $job", GearmanManager::LOG_LEVEL_WORKER_INFO);

        $this->log_result($handle, $result);
    }

    /**
     * Logs the result of complete/failed jobs
     *
     * @param   mixed   $result     Result returned from worker
     * @return  void
     *
     */
    private function log_result($handle, $result) {

        if(!empty(self::$LOG)){
            foreach(self::$LOG as $l){

                if(!is_scalar($l)){
                    $l = explode("\n", trim(print_r($l, true)));
                } elseif(strlen($l) > 256){
                    $l = substr($l, 0, 256)."...(truncated)";
                }

                if(is_array($l)){
                    $log_message = "";
                    foreach($l as $ln){
                        $log_message.= "($handle) $ln\n";
                    }
                    $this->log($log_message, GearmanManager::LOG_LEVEL_WORKER_INFO);
                } else {
                    $this->log("($handle) $l", GearmanManager::LOG_LEVEL_WORKER_INFO);
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
            $log_message = "";
            foreach($result_log as $ln){
                $log_message.="($handle) $ln\n";
            }
            $this->log($log_message, GearmanManager::LOG_LEVEL_DEBUG);
        } else {
            $this->log("($handle) $result_log", GearmanManager::LOG_LEVEL_DEBUG);
        }
    }


    /**
     * Validates the PECL compatible worker files/functions
     */
    protected function validate_lib_workers($worker_files) {

        require "Net/Gearman/Job/Common.php";

        foreach($worker_files as $file){
            $class = NET_GEARMAN_JOB_CLASS_PREFIX.substr(basename($file), 0, -4);
            include $file;
            if(!class_exists($class) && !method_exists($class, "run")) {
                $this->log("Class $class not found in $file or run method not present");
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
        }
    }

}

$mgr = new GearmanPearManager();

?>