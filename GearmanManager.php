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

/**
 * Class that handles all the process management
 */
class GearmanManager {

    /**
     * Log levels can be enabled from the command line with -v, -vv, -vvv
     */
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_PROC_INFO = 2;
    const LOG_LEVEL_WORKER_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;
    const LOG_LEVEL_CRAZY = 5;

    /**
     * Holds the worker configuration
     */
    protected $config = array();

    /**
     * Boolean value that determines if the running code is the parent or a child
     */
    protected $isparent = true;

    /**
     * When true, workers will stop look for jobs and the parent process will
     * kill off all running children
     */
    protected $stop_work = false;

    /**
     * The timestamp when the signal was received to stop working
     */
    protected $stop_time = 0;

    /**
     * Holds the resource for the log file
     */
    protected $log_file_handle;

    /**
     * Flag for logging to syslog
     */
    protected $log_syslog = false;

    /**
     * Verbosity level for the running script. Set via -v option
     */
    protected $verbose = 0;

    /**
     * The array of running child processes
     */
    protected $children = array();

    /**
     * The array of jobs that have workers running
     */
    protected $jobs = array();

    /**
     * The PID of the running process. Set for parent and child processes
     */
    protected $pid = 0;

    /**
     * PID file for the parent process
     */
    protected $pid_file = "";

    /**
     * Class/Function Prefix
     */
    protected $prefix = "";

    /**
     * PID of helper child
     */
    protected $helper_pid = 0;

    /**
     * Restart worker after each job
     */
    protected $restart_each = false;

    /**
     * If true, the worker code directory is checked for updates and workers
     * are restarted automatically.
     */
    protected $check_code = false;

    /**
     * Holds the last timestamp of when the code was checked for updates
     */
    protected $last_check_time = 0;

    /**
     * When forking helper children, the parent waits for a signal from them
     * to continue doing anything
     */
    protected $wait_for_signal = false;

    /**
     * Directory where worker functions are found
     */
    protected $worker_dir = "";

    /**
     * Worker functions to ignore
     */
    protected $ignore_workers = array();

    /**
     * Number of workers that do all jobs
     */
    protected $do_all_count = 0;

    /**
     * Maximum time a worker will run
     */
    protected $max_run_time = 3600;

    /**
     * Servers that workers connect to
     */
    protected $servers = array();

    /**
     * List of functions available for work
     */
    protected $functions = array();

    /**
     * Creates the manager and gets things going
     *
     */
    public function __construct() {

        if(!function_exists("posix_kill")){
            $this->show_help("The function posix_kill was not found. Please ensure POSIX functions are installed");
        }

        if(!function_exists("pcntl_fork")){
            $this->show_help("The function pcntl_fork was not found. Please ensure Process Control functions are installed");
        }

        $this->pid = getmypid();

        /**
         * Parse command line options. Loads the config file as well
         */
        $this->getopt();

        /**
         * Register signal listeners
         */
        $this->register_ticks();

        /**
         * Load up the workers
         */
        $worker_files = glob($this->worker_dir."/*.php");

        if(empty($worker_files)){
            $this->log("No workers found");
            posix_kill($this->pid, SIGUSR1);
            exit();
        }

        foreach($worker_files as $file){
            $function = substr(basename($file), 0, -4);
            if(!isset($this->functions[$function])){
                $this->functions[$function] = array("count"=>1);
            }
        }


        /**
         * Validate workers in the helper process
         */
        $this->fork_me("validate_workers");

        $this->log("Started with pid $this->pid", GearmanManager::LOG_LEVEL_PROC_INFO);

        /**
         * Start the initial workers and set up a running environment
         */
        $this->bootstrap();


        /**
         * Main processing loop for the parent process
         */
        while(!$this->stop_work || count($this->children)) {

            $status = null;

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


            if($this->stop_work && time() - $this->stop_time > 60){
                $this->log("Children have not exited, killing.", GearmanManager::LOG_LEVEL_PROC_INFO);
                $this->stop_children(SIGKILL);
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
        if($this->isparent){
            if(!empty($this->pid_file) && file_exists($this->pid_file)){
                unlink($this->pid_file);
            }
        }
    }

    /**
     * Parses the command line options
     *
     */
    protected function getopt() {

        $opts = getopt("ac:dD:h:Hi:l:o:P:p:ru:v::w:x:");

        if(isset($opts["H"])){
            $this->show_help();
        }

        /**
         * If we want to daemonize, fork here and exit
         */
        if(isset($opts["d"])){
            $pid = pcntl_fork();
            if($pid>0){
                $this->isparent = false;
                exit();
            }
            posix_setsid();
            $this->pid = getmypid();
        }

        if (isset($opts['r'])) {
            $this->restart_each = true;
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

        if(isset($opts['u'])) {
            $user = posix_getpwnam($opts['u']);
            if (!$user || !isset($user['uid'])) {
                $this->show_help("User ({$opts['u']}) not found.");
            }

            posix_setuid($user['uid']);
            if (posix_geteuid() != $user['uid']) {
                $this->show_help("Unable to change user to {$opts['u']} (UID: {$user['uid']}).");
            }
        }

        if(isset($opts["v"])){
            switch($opts["v"]){
                case false:
                    $this->verbose = GearmanManager::LOG_LEVEL_INFO;
                    break;
                case "v":
                    $this->verbose = GearmanManager::LOG_LEVEL_PROC_INFO;
                    break;
                case "vv":
                    $this->verbose = GearmanManager::LOG_LEVEL_WORKER_INFO;
                    break;
                case "vvv":
                    $this->verbose = GearmanManager::LOG_LEVEL_DEBUG;
                    break;
                default:
                case "vvvv":
                    $this->verbose = GearmanManager::LOG_LEVEL_CRAZY;
                    break;
            }
        }

        if(isset($opts["l"])){
            if($opts["l"] === 'syslog'){
                $this->log_syslog = true;
            } else {
                $this->log_file_handle = @fopen($opts["l"], "a");
                if(!$this->log_file_handle){
                    $this->show_help("Could not open log file $opts[l]");
                }
            }
        }

        if(isset($opts["c"]) && !file_exists($opts["c"])){
            $this->show_help("Config file $opts[c] not found.");
        }

        if(isset($opts["a"])){
            $this->check_code = true;
        }

        if(isset($opts["w"])){
            $this->worker_dir = $opts["w"];
        } else {
            $this->worker_dir = "./workers";
        }

        if (isset($opts["i"])) {
            if (!is_array($opts["i"])) {
                $this->ignore_workers = array($opts["i"]);
            } else {
                $this->ignore_workers = $opts["i"];
            }
        }

        if(!file_exists($this->worker_dir)){
            $this->show_help("Worker dir ".$this->worker_dir." not found");
        }


        if(isset($opts["x"])){
            $this->max_run_time = (int)$opts["x"];
        }

        if(isset($opts["D"])){
            $this->do_all_count = (int)$opts["D"];
        }

        if(isset($opts["h"])){
            if(!is_array($opts["h"])){
                $this->servers = array($opts["h"]);
            } else {
                $this->servers = $opts["h"];
            }
        } else {
            $this->servers = array("127.0.0.1");
        }

        if(isset($opts["p"])){
            $this->prefix = $opts["p"];
        }

        /**
         * parse the config file
         */
        if(isset($opts["c"])){
            $this->parse_config($opts["c"]);
        }

    }


    /**
     * Parses the config file
     *
     * @param   string    $file     The config file. Just pass so we don't have
     *                              to keep it around in a var
     */
    protected function parse_config($file) {

        $this->log("Loading configuration from $file");

        if(substr($file, -4) == ".php"){

            require $file;

        } elseif(substr($file, -4) == ".ini"){

            $gearman_config = parse_ini_file($file, true);

        }

        if(empty($gearman_config)){
            $this->show_help("No configuration found in $file");
        }

        foreach($gearman_config as $function=>$data){
            $this->functions[$function] = $data;
        }

    }

    /**
     * Forks the process and runs the given method. The parent then waits
     * for the child process to signal back that it can continue
     *
     * @param   string  $method  Class method to run after forking
     *
     */
    protected function fork_me($method){
        $this->wait_for_signal = true;
        $pid = pcntl_fork();
        switch($pid) {
            case 0:
                $this->isparent = false;
                $this->$method();
                break;
            case -1:
                $this->log("Failed to fork");
                $this->stop_work = true;
                break;
            default:
                $this->helper_pid = $pid;
                while($this->wait_for_signal && !$this->stop_work) {
                    usleep(5000);
                }
                break;
        }
    }


    /**
     * Forked method that validates the worker code and checks it if desired
     *
     */
    protected function validate_workers(){

        $parent_pid = $this->pid;
        $this->pid = getmypid();

        $this->log("Helper forked", GearmanManager::LOG_LEVEL_PROC_INFO);

        $this->log("Loading workers in ".$this->worker_dir);

        $worker_files = glob($this->worker_dir."/*.php");

        if(empty($worker_files)){
            $this->log("No workers found");
            posix_kill($parent_pid, SIGUSR1);
            exit();
        }

        $this->validate_lib_workers($worker_files);

        /**
         * Since we got here, all must be ok, send a CONTINUE
         */
        posix_kill($parent_pid, SIGCONT);

        if($this->check_code){
            $this->log("Running loop to check for new code", self::LOG_LEVEL_DEBUG);
            $last_check_time = 0;
            while(1) {
                $max_time = 0;
                foreach($worker_files as $f){
                    clearstatcache();
                    $mtime = filemtime($f);
                    $max_time = max($max_time, $mtime);
                    $this->log("$f - $mtime $last_check_time", self::LOG_LEVEL_CRAZY);
                    if($last_check_time!=0 && $mtime > $last_check_time){
                        $this->log("New code found. Sending SIGHUP", self::LOG_LEVEL_PROC_INFO);
                        posix_kill($parent_pid, SIGHUP);
                        break;
                    }
                }
                $last_check_time = $max_time;
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
    protected function bootstrap() {

        $function_count = array();

        /**
         * If we have "do_all" workers, start them first
         * do_all workers register all functions
         */
        if(!empty($this->do_all_count) && is_int($this->do_all_count)){

            for($x=0;$x<$this->do_all_count;$x++){
                $this->start_worker();
            }

            foreach(array_keys($this->functions) as $worker){
                $function_count[$worker] = $this->do_all_count;
            }

        }

        /**
         * Next we loop the workers and ensure we have enough running
         * for each worker
         */
        foreach($this->functions as $worker=>$config) {

            if(empty($function_count[$worker])){
                $function_count[$worker] = 0;
            }

            while($function_count[$worker] < $config["count"]){
                $this->start_worker($worker);
                $function_count[$worker]++;;
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

    protected function start_worker($worker="all") {

        if (in_array($worker, $this->ignore_workers)) {
            return;
        }

        $pid = pcntl_fork();

        switch($pid) {

            case 0:

                $this->isparent = false;

                $this->register_ticks(false);

                $this->pid = getmypid();

                if($worker == "all"){
                    $worker_list = array_keys($this->functions);
                } else {
                    $worker_list = array($worker);
                }

                $this->start_lib_worker($worker_list);

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
     * Stops all running children
     */
    protected function stop_children($signal=SIGTERM) {
        $this->log("Stopping children", GearmanManager::LOG_LEVEL_PROC_INFO);

        foreach($this->children as $pid=>$worker){
            $this->log("Stopping child $pid ($worker)", GearmanManager::LOG_LEVEL_PROC_INFO);
            posix_kill($pid, $signal);
        }

    }

    /**
     * Registers the process signal listeners
     */
    protected function register_ticks($parent=true) {

        if($parent){
            $this->log("Registering signals for parent", GearmanManager::LOG_LEVEL_DEBUG);
            pcntl_signal(SIGTERM, array($this, "signal"));
            pcntl_signal(SIGINT,  array($this, "signal"));
            pcntl_signal(SIGUSR1,  array($this, "signal"));
            pcntl_signal(SIGUSR2,  array($this, "signal"));
            pcntl_signal(SIGCONT,  array($this, "signal"));
            pcntl_signal(SIGHUP,  array($this, "signal"));
        } else {
            $this->log("Registering signals for child", GearmanManager::LOG_LEVEL_DEBUG);
            $res = pcntl_signal(SIGTERM, array($this, "signal"));
            if(!$res){
                exit();
            }
        }
    }

    /**
     * Handles signals
     */
    public function signal($signo) {

        static $term_count = 0;

        if(!$this->isparent){

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
                    $this->stop_time = time();
                    $term_count++;
                    if($term_count < 5){
                        $this->stop_children();
                    } else {
                        $this->stop_children(SIGKILL);
                    }
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
    protected function log($message, $level=GearmanManager::LOG_LEVEL_INFO) {

        static $init = false;

        if($level > $this->verbose) return;

        if ($this->log_syslog) {
            $this->syslog($message, $level);
            return;
        }

        if(!$init){
            $init = true;

            if($this->log_file_handle){
                $ds = date("Y-m-d H:i:s");
                fwrite($this->log_file_handle, "Date                  PID   Type   Message\n");
            } else {
                echo "PID   Type   Message\n";
            }

        }

        $label = "";

        switch($level) {
            case GearmanManager::LOG_LEVEL_INFO;
                $label = "INFO  ";
                break;
            case GearmanManager::LOG_LEVEL_PROC_INFO:
                $label = "PROC  ";
                break;
            case GearmanManager::LOG_LEVEL_WORKER_INFO:
                $label = "WORKER";
                break;
            case GearmanManager::LOG_LEVEL_DEBUG:
                $label = "DEBUG ";
                break;
            case GearmanManager::LOG_LEVEL_CRAZY:
                $label = "CRAZY ";
                break;
        }


        $log_pid = str_pad($this->pid, 5, " ", STR_PAD_LEFT);

        if($this->log_file_handle){
            $ds = date("Y-m-d H:i:s");
            $prefix = "[$ds] $log_pid $label";
            fwrite($this->log_file_handle, $prefix." ".str_replace("\n", "\n$prefix ", trim($message))."\n");
        } else {
            $prefix = "$log_pid $label";
            echo $prefix." ".str_replace("\n", "\n$prefix ", trim($message))."\n";
        }

    }

    /**
     * Logs data to syslog
     */
    protected function syslog($message, $level) {
        switch($level) {
            case GearmanManager::LOG_LEVEL_INFO;
            case GearmanManager::LOG_LEVEL_PROC_INFO:
            case GearmanManager::LOG_LEVEL_WORKER_INFO:
            default:
                $priority = LOG_INFO;
                break;
            case GearmanManager::LOG_LEVEL_DEBUG:
                $priority = LOG_DEBUG;
                break;
        }

        if (!syslog($priority, $message)) {
            echo "Unable to write to syslog\n";
        }
    }

    /**
     * Shows the scripts help info with optional error message
     */
    protected function show_help($msg = "") {
        if($msg){
            echo "ERROR:\n";
            echo "  ".wordwrap($msg, 72, "\n  ")."\n\n";
        }
        echo "Gearman worker manager script\n\n";
        echo "USAGE:\n";
        echo "  # ".basename(__FILE__)." -h | -c CONFIG [-v] [-l LOG_FILE] [-d] [-v] [-a] [-P PID_FILE]\n\n";
        echo "OPTIONS:\n";
        echo "  -a             Automatically check for new worker code\n";
        echo "  -c CONFIG      Worker configuration file\n";
        echo "  -d             Daemon, detach and run in the background\n";
        echo "  -D NUMBER      Start NUMBER workers that do all jobs\n";
        echo "  -h HOST[:PORT] Connect to HOST and optional PORT\n";
        echo "  -H             Shows this help\n";
        echo "  -i WORKER      Ignore WORKER\n";
        echo "  -l LOG_FILE    Log output to LOG_FILE or use keyword 'syslog' for syslog support\n";
        echo "  -p PREFIX      Prefix function/class name with PREFIX\n";
        echo "  -P PID_FILE    File to write process ID out to\n";
        echo "  -r             Restart workers after each job is complete\n";
        echo "  -u USERNAME    Run wokers as USERNAME\n";
        echo "  -v             Increase verbosity level by one\n";
        echo "  -w DIR         Directory where workers are located\n";
        echo "  -x SECONDS     Maximum seconds for a worker to live\n";
        echo "\n";
        exit();
    }

}

?>
