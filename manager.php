#!/usr/bin/env php
<?php

/**

PHP script for managing PHP based Gearman workers

Copyright (c) 2010, Brian Moon
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
 * Neither the name of Brian Moon nor the names of its contributors may be
   used to endorse or promote products derived from this software without
   specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.

**/

declare(ticks = 1);

error_reporting(E_ALL | E_STRICT);

/**
 * Class that handles all the process management
 */
class GearmanManager {

    /**
     * Log levels can be enabled from the command line with -v, -vv, -vvv
     */
    const LOG_LEVEL_ERROR  = 1;
    const LOG_LEVEL_PROC_INFO = 2;
    const LOG_LEVEL_WORKER_INFO = 3;

    /**
     * Holds the worker configuration
     */
    private $config = array();

    /**
     * Boolean value that determines if the running code is the parent or a child
     */
    private $ischild = false;

    /**
     * When true, workers will stop look for jobs and the parent process will
     * kill off all running children
     */
    private $stop_work = false;

    /**
     * Holds the resource for the log file
     */
    private $log_file_handle;

    /**
     * Verbosity level for the running script. Set via -v option
     */
    private $verbose = 0;

    /**
     * The array of running child processes
     */
    private $children = array();

    /**
     * The array of jobs that have workers running
     */
    private $jobs = array();

    /**
     * The PID of the running process. Set for parent and child processes
     */
    private $pid = 0;

    /**
     * PID file for the parent process
     */
    private $pid_file = "";

    /**
     * PID of helper child
     */
    private $helper_pid = 0;

    /**
     * If true, the worker code directory is checked for updates and workers
     * are restarted automatically.
     */
    private $check_code = false;

    /**
     * Holds the last timestamp of when the code was checked for updates
     */
    private $last_check_time = 0;

    /**
     * When forking helper children, the parent waits for a signal from them
     * to continue doing anything
     */
    private $wait_for_signal = false;

    /**
     * Creates the manager and gets things going
     *
     */
    public function __construct() {

        if(!class_exists("GearmanWorker")){
            $this->show_help("GearmanWorker class not found. Please ensure the gearman extenstion is installed");
        }

        if(!function_exists("posix_kill")){
            $this->show_help("The function posix_kill was not found. Please ensure POSIX functions are installed");
        }

        if(!function_exists("pcntl_fork")){
            $this->show_help("The function pcntl_fork was not found. Please ensure Process Control functions are installed");
        }

        $this->pid = getmypid();

        /**
         * Register signal listeners
         */
        $this->register_ticks();

        /**
         * Parse command line options. Loads the config file as well
         */
        $this->getopt();

        $this->log("Started with pid $this->pid", GearmanManager::LOG_LEVEL_PROC_INFO);

        /**
         * Start the initial workers and set up a running environment
         */
        $this->bootstrap();


        /**
         * Main processing loop for the parent process
         */
        while(!$this->stop_work || count($this->children)) {

            /**
             * Check for exited children
             */
            $exited = pcntl_wait( $status, WNOHANG );

            /**
             * We run other children, make sure this is a worker
             */
            if(isset($this->children[$exited])){
                /**
                 * If they have exited, remove them from the children array
                 * If we are not stopping work, start another in its place
                 */
                if($exited) {
                    $worker = $this->children[$exited];
                    unset($this->children[$exited]);
                    $this->log("Child $exited exited ($worker)", GearmanManager::LOG_LEVEL_PROC_INFO);
                    if(!$this->stop_work){
                        $this->start_worker($worker);
                    }
                }
            }


            /**
             * php will eat up your cpu if you don't have this
             */
            usleep(50000);

        }

        /**
         * Kill the helper if it is running
         */
        if(isset($this->helper_pid)){
            posix_kill($this->helper_pid, SIGKILL);
        }

        $this->log("Exiting");

    }


    /**
     * Handles anything we need to do when we are shutting down
     *
     */
    public function __destruct() {
        if(!$this->ischild){
            if(!empty($this->pid_file) && file_exists($this->pid_file)){
                unlink($this->pid_file);
            }
        }
    }

    /**
     * Parses the command line options
     *
     */
    private function getopt() {

        $opts = getopt("ac:dhl:P:v::");

        if(isset($opts["h"])){
            $this->show_help();
        }

        /**
         * If we want to daemonize, fork here and exit
         */
        if(isset($opts["d"])){
            $pid = pcntl_fork();
            if($pid>0){
                exit();
            }
        }

        if(isset($opts["P"])){
            $fp = @fopen($opts["P"], "w");
            if($fp){
                fwrite($fp, $this->pid);
                fclose($fp);
            } else {
                $this->show_help("Unable to write PID to $opts[P]");
            }
            $this->pid_file = $opts["P"];
        }

        if(isset($opts["v"])){
            switch($opts["v"]){
                case false:
                    $this->verbose = GearmanManager::LOG_LEVEL_ERROR;
                    break;
                case "v":
                    $this->verbose = GearmanManager::LOG_LEVEL_PROC_INFO;
                    break;
                case "vv":
                default:
                    $this->verbose = GearmanManager::LOG_LEVEL_WORKER_INFO;
            }
        }

        if(isset($opts["l"])){
            $this->log_file_handle = @fopen($opts["l"], "a");
            if(!$this->log_file_handle){
                $this->show_help("Could not open log file $opts[l]");
            }
        }

        if(empty($opts["c"]) || !file_exists($opts["c"])){
            $this->show_help("Config file not found.");
        }

        if(isset($opts["a"])){
            $this->check_code = true;
        }

        /**
         * parse the config file
         */
        $this->parse_config($opts["c"]);

    }


    /**
     * Parses the config file
     *
     * @param   string    $file     The config file. Just pass so we don't have
     *                              to keep it around in a var
     */
    private function parse_config($file) {

        $this->log("Loading configuration from $file");

        require $file;

        if(!isset($gearman_config)){
            $this->show_help("No configuration found in $file");
        }

        $this->config = $gearman_config;

        if(empty($this->config["worker_dir"])){
            $this->config["worker_dir"] = ".";
        }

        if(!file_exists($this->config["worker_dir"])){
            $this->show_help("Worker dir ".$this->config["worker_dir"]." not found");
        }

        $this->log("Loading workers in ".$this->config["worker_dir"]);

        $this->fork_me("validate_workers");

        $worker_files = glob($this->config["worker_dir"]."/*.php");

        if(empty($worker_files)){
            $this->show_help("No workers found in ".$this->config["worker_dir"]);
        }

        foreach($worker_files as $file){
            $function = substr(basename($file), 0, -4);
            if(!isset($this->config["workers"][$function])){
                $this->config["workers"][$function] = array(
                    "count" => 1
                );
            }
        }


        if(!isset($this->config["max_run_time"])){
            /**
             * Default run time to one hour
             */
            $this->config["max_run_time"] = 3600;
        }

        if(!isset($this->config["servers"])){
            /**
             * Default to localhost
             */
            $this->config["servers"] = array("127.0.0.1");
        } elseif(!is_array($this->config["servers"])) {

            $this->show_help("Invalid value for servers in $file");
        }

        if(!isset($this->config["timeout"])){
            /**
             * Default timeout to 5 minutes
             */
            $this->config["timeout"] = 300;
        }

    }

    /**
     * Forks the process and runs the given method. The parent then waits
     * for the child process to signal back that it can continue
     *
     * @param   string  $method  Class method to run after forking
     *
     */
    private function fork_me($method){
        $this->wait_for_signal = true;
        $pid = pcntl_fork();
        switch($pid) {
            case 0:
                $this->$method();
                break;
            case -1:
                $this->log("Failed to fork");
                $this->stop_work = true;
                break;
            default:
                $this->helper_pid = $pid;
                while($this->wait_for_signal) {
                    usleep(5000);
                }
                break;
        }
    }


    /**
     * Forked method that validates the worker code and checks it if desired
     *
     */
    private function validate_workers(){

        $this->log("Helper forked", GearmanManager::LOG_LEVEL_PROC_INFO);

        $worker_files = glob($this->config["worker_dir"]."/*.php");

        if(empty($worker_files)){
            $this->log("No workers found");
            posix_kill($this->pid, SIGUSR1);
            exit();
        }

        foreach($worker_files as $file){
            $function = substr(basename($file), 0, -4);
            @include $file;
            if(!function_exists($function)){
                $this->log("Function $function not found in $file");
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
        }

        /**
         * Since we got here, all must be ok, send a CONTINUE
         */
        posix_kill($this->pid, SIGCONT);

        if($this->check_code){
            $last_check_time = time();
            while(1) {
                foreach($worker_files as $f){
                    clearstatcache();
                    $mtime = filemtime($f);
                    if($mtime > $last_check_time){
                        posix_kill($this->pid, SIGHUP);
                        break;
                    }
                }
                $last_check_time = time();
                sleep(5);
            }
        } else {
            exit();
        }

    }

    /**
     * Bootstap a set of workers and any vars that need to be set
     *
     */
    private function bootstrap() {

        /**
         * If we have "do_all" workers, start them first
         * do_all workers register all functions
         */
        if(!empty($this->config["do_all"]) && is_int($this->config["do_all"])){

            for($x=0;$x<$this->config["do_all"];$x++){
                $this->start_worker();
            }

            foreach(array_keys($this->config["workers"]) as $worker){
                $this->workers[$worker] = $this->config["do_all"];
            }

        }

        /**
         * Next we loop the workers and ensure we have enough running
         * for each worker
         */
        foreach($this->config["workers"] as $worker=>$config) {

            if(empty($this->workers[$worker])){
                $this->workers[$worker] = 0;
            }

            while($this->workers[$worker] < $config["count"]){
                $this->start_worker($worker);
                $this->workers[$worker]++;;
            }

            /**
             * php will eat up your cpu if you don't have this
             */
            usleep(50000);

        }

        /**
         * Set the last code check time to now since we just loaded all the code
         */
        $this->last_check_time = time();

    }


    /**
     * Starts a child process and creates a GearmanWorker object
     */
    private function start_worker($worker="all") {

        $pid = pcntl_fork();

        switch($pid) {

            case 0:

                $this->ischild = true;

                $this->pid = getmypid();

                if($worker == "all"){
                    $worker_list = array_keys($this->config["workers"]);
                } else {
                    $worker_list = array($worker);
                }

                $thisWorker = new GearmanWorker();

                $thisWorker->addOptions(GEARMAN_WORKER_NON_BLOCKING);

                $thisWorker->setTimeout(5000);

                foreach($this->config["servers"] as $s){
                    $this->log("Adding server $s", GearmanManager::LOG_LEVEL_WORKER_INFO);
                    $thisWorker->addServer($s);
                }

                foreach($worker_list as $w){
                    $this->log("Adding job $w", GearmanManager::LOG_LEVEL_WORKER_INFO);
                    $thisWorker->addFunction($w, array($this, "do_job"), $this);
                }

                $this->register_ticks(false);

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
                    if($this->config["max_run_time"] > 0 && time() - $start > $this->config["max_run_time"]) {
                        $this->log("Been running too long, exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);
                        $this->stop_work = true;
                    }

                }

                $thisWorker->unregisterAll();

                $this->log("Child exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);

                exit();

                break;

            case -1:

                $this->log("Could not fork");
                $this->stop_work = true;
                $this->stop_children();
                break;

            default:

                // parent
                $this->log("Started child $pid ($worker)", GearmanManager::LOG_LEVEL_PROC_INFO);
                $this->children[$pid] = $worker;
        }

    }

    /**
     * Wrapper function handler for all registered functions
     * This allows us to do some nice logging when jobs are started/finished
     */
    public function do_job($job) {

        $w = $job->workload();

        $h = $job->handle();

        $f = $job->functionName();

        if(!function_exists($f)){

            @include $this->config["worker_dir"]."/$f.php";
            if(!function_exists($f)){
                $this->log("Function $f not found");
                return;
            }

        }

        $this->log("($h) Job: $f", GearmanManager::LOG_LEVEL_WORKER_INFO);

        $this->log("($h) Workload: $w", GearmanManager::LOG_LEVEL_WORKER_INFO);

        /**
         * Run the real function here
         */
        $result = $f($job);

        $return = $result;

        if(is_array($result)){
            if(isset($result["log"])){
                $this->log("($h) Result: $result[log]", GearmanManager::LOG_LEVEL_WORKER_INFO);
            }
            if(isset($result["result"])){
                $return = $result["result"];
            }
        }

        if(is_scalar($return)){
            $log = $return;
        } else {
            $log = print_r($return, true);
        }

        if(strlen($log) > 256){
            $log = substr($log, 0, 256)."...(truncated)";
        }

        $this->log("($h) Return: $log", GearmanManager::LOG_LEVEL_WORKER_INFO);

        return $return;

    }

    /**
     * Stops all running children
     */
    private function stop_children() {
        $this->log("Stopping children", GearmanManager::LOG_LEVEL_PROC_INFO);

        foreach($this->children as $pid=>$worker){
            $this->log("Stopping child $pid ($worker)", GearmanManager::LOG_LEVEL_PROC_INFO);
            posix_kill($pid, SIGTERM);
        }

    }

    /**
     * Registers the process signal listeners
     */
    private function register_ticks($parent=true) {

        if($parent){
            pcntl_signal(SIGTERM, array($this, "signal"));
            pcntl_signal(SIGINT,  array($this, "signal"));
            pcntl_signal(SIGUSR1,  array($this, "signal"));
            pcntl_signal(SIGUSR2,  array($this, "signal"));
            pcntl_signal(SIGCONT,  array($this, "signal"));
            pcntl_signal(SIGHUP,  array($this, "signal"));
        } else {
            pcntl_signal(SIGTERM, array($this, "signal"));
        }
    }

    /**
     * Handles signals
     */
    public function signal($signo) {

        if($this->ischild){

            $this->stop_work = true;

        } else {

            switch ($signo) {
                case SIGUSR1:
                    $this->show_help("No worker files could be found");
                    break;
                case SIGUSR2:
                    $this->show_help("Error validating worker functions");
                    break;
                case SIGCONT:
                    $this->wait_for_signal = false;
                    break;
                case SIGINT:
                case SIGTERM:
                    $this->log("Shutting down...");
                    $this->stop_work = true;
                    $this->stop_children();
                    break;
                case SIGHUP:
                    $this->log("Restarting children", GearmanManager::LOG_LEVEL_PROC_INFO);
                    $this->stop_children();
                    break;
                default:
                // handle all other signals
            }
        }

    }

    /**
     * Logs data to disk or stdout
     */
    public function log($message, $level=GearmanManager::LOG_LEVEL_ERROR) {

        if($level > $this->verbose) return;

        $ds = date("Y-m-d H:i:s");

        if($this->log_file_handle){
            fwrite($this->log_file_handle, "[$ds] $message\n");
        } else {
            echo "[$ds] ($this->pid) $message\n";
        }

    }

    /**
     * Shows the scripts help info with optional error message
     */
    private function show_help($msg = "") {
        if($msg){
            echo "ERROR:\n";
            echo "  ".wordwrap($msg, 72, "\n  ")."\n\n";
        }
        echo "Gearman worker manager script\n\n";
        echo "USAGE:\n";
        echo "  # ".__FILE__." -h | -c CONFIG [-v] [-l LOG_FILE] [-d] [-v] [-a] [-p PID_FILE]\n\n";
        echo "OPTIONS:\n";
        echo "  -a           Automatically check for new worker code\n";
        echo "  -c CONFIG    Worker configuration file\n";
        echo "  -d           Daemon, detach and run in the background.\n";
        echo "  -h           Shows this help\n";
        echo "  -l LOG_FILE  Log output to LOG_FILE\n";
        echo "  -P PID_FILE  File to write process ID out to.\n";
        echo "  -v           Increase verbosity level by one.\n";
        echo "\n";
        exit();
    }

}

/**
 * Fire up a manager
 */
$worker = new GearmanManager();

?>