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
abstract class GearmanManager {

    /**
     * Log levels can be enabled from the command line with -v, -vv, -vvv
     */
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_PROC_INFO = 2;
    const LOG_LEVEL_WORKER_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;
    const LOG_LEVEL_CRAZY = 5;

    /**
     * Default config section name
     */
    const DEFAULT_CONFIG = "GearmanManager";

    /**
     * Defines job priority limits
     */
    const MIN_PRIORITY = -5;
    const MAX_PRIORITY = 5;

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
     * The filename to log to
     */
    protected $log_file;

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
     * The PID of the parent process, when running in the forked helper.
     */
    protected $parent_pid = 0;

    /**
     * PID file for the parent process
     */
    protected $pid_file = "";

    /**
     * PID of helper child
     */
    protected $helper_pid = 0;

    /**
     * The user to run as
     */
    protected $user = null;

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
     * Number of workers that do all jobs
     */
    protected $do_all_count = 0;

    /**
     * Maximum time a worker will run
     */
    protected $max_run_time = 3600;

    /**
     * Maximum number of jobs this worker will do before quitting
     */
    protected $max_job_count = 0;

    /**
     * Maximum job iterations per worker
     */
    protected $max_runs_per_worker = null;

    /**
     * Number of times this worker has run a job
     */
    protected $job_execution_count = 0;

    /**
     * Servers that workers connect to
     */
    protected $servers = array();

    /**
     * List of functions available for work
     */
    protected $functions = array();

    /**
     * Function/Class prefix
     */
    protected $prefix = "";

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
        $this->load_workers();

        if(empty($this->functions)){
            $this->log("No workers found");
            posix_kill($this->pid, SIGUSR1);
            exit();
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

            $this->process_loop();

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

    protected function process_loop() {

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

    }


    /**
     * Handles anything we need to do when we are shutting down
     *
     */
    public function __destruct() {
        if($this->isparent){
            if(!empty($this->pid_file) && file_exists($this->pid_file)){
                if(!unlink($this->pid_file)) {
                    $this->log("Could not delete PID file", GearmanManager::LOG_LEVEL_PROC_INFO);
                }
            }
        }
    }

    /**
     * Parses the command line options
     *
     */
    protected function getopt() {

        $opts = getopt("ac:dD:h:Hl:o:p:P:u:v::w:r:x:Z");

        if(isset($opts["H"])){
            $this->show_help();
        }

        if(isset($opts["c"]) && !file_exists($opts["c"])){
            $this->show_help("Config file $opts[c] not found.");
        }

        /**
         * parse the config file
         */
        if(isset($opts["c"])){
            $this->parse_config($opts["c"]);
        }

        /**
         * command line opts always override config file
         */
        if (isset($opts['P'])) {
            $this->config['pid_file'] = $opts['P'];
        }

        if(isset($opts["l"])){
            if($opts["l"] === 'syslog'){
                $this->log_syslog = true;
            } else {
                $this->log_file = $opts["l"];
                $this->open_log_file($this->log_file);
            }
        }

        if (isset($opts['a'])) {
            $this->config['auto_update'] = 1;
        }

        if (isset($opts['w'])) {
            $this->config['worker_dir'] = $opts['w'];
        }

        if (isset($opts['x'])) {
            $this->config['max_worker_lifetime'] = (int)$opts['x'];
        }

        if (isset($opts['r'])) {
            $this->config['max_runs_per_worker'] = (int)$opts['r'];
        }

        if (isset($opts['D'])) {
            $this->config['count'] = (int)$opts['D'];
        }

        if (isset($opts['h'])) {
            $this->config['host'] = $opts['h'];
        }

        if (isset($opts['p'])) {
            $this->prefix = $opts['p'];
        } elseif(!empty($this->config['prefix'])) {
            $this->prefix = $this->config['prefix'];
        }

        if(isset($opts['u'])){
            $this->user = $opts['u'];
        } elseif(isset($this->config["user"])){
            $this->user = $this->config["user"];
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
            $this->pid = getmypid();
            posix_setsid();
        }

        if(!empty($this->config['pid_file'])){
            $fp = @fopen($this->config['pid_file'], "w");
            if($fp){
                fwrite($fp, $this->pid);
                fclose($fp);
            } else {
                $this->show_help("Unable to write PID to {$this->config['pid_file']}");
            }
            $this->pid_file = $this->config['pid_file'];
        }

        if(!empty($this->config['log_file'])){
            if($this->config['log_file'] === 'syslog'){
                $this->log_syslog = true;
            } else {
                $this->log_file = $this->config['log_file'];
                $this->open_log_file($this->log_file);
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
                case "vvvv":
                default:
                    $this->verbose = GearmanManager::LOG_LEVEL_CRAZY;
                    break;
            }
        }

        if($this->user) {
            $user = posix_getpwnam($this->user);
            if (!$user || !isset($user['uid'])) {
                $this->show_help("User ({$this->user}) not found.");
            }

            /**
             * Ensure new uid can read/write pid and log files
             */
            if(!empty($this->pid_file)){
                if(!chown($this->pid_file, $user['uid'])){
                    $this->log("Unable to chown PID file to {$this->user}", GearmanManager::LOG_LEVEL_PROC_INFO);
                }
            }
            if(!empty($this->log_file_handle)){
                if(!chown($this->log_file, $user['uid'])){
                    $this->log("Unable to chown log file to {$this->user}", GearmanManager::LOG_LEVEL_PROC_INFO);
                }
            }

            posix_setuid($user['uid']);
            if (posix_geteuid() != $user['uid']) {
                $this->show_help("Unable to change user to {$this->user} (UID: {$user['uid']}).");
            }
            $this->log("User set to {$this->user}", GearmanManager::LOG_LEVEL_PROC_INFO);
        }

        if(!empty($this->config['auto_update'])){
            $this->check_code = true;
        }

        if(!empty($this->config['worker_dir'])){
            $this->worker_dir = $this->config['worker_dir'];
        } else {
            $this->worker_dir = "./workers";
        }

        $dirs = explode(",", $this->worker_dir);
        foreach($dirs as &$dir){
            $dir = trim($dir);
            if(!file_exists($dir)){
                $this->show_help("Worker dir ".$dir." not found");
            }
        }
        unset($dir);

        if(!empty($this->config['max_worker_lifetime'])){
            $this->max_run_time = (int)$this->config['max_worker_lifetime'];
        }

        if(!empty($this->config['count'])){
            $this->do_all_count = (int)$this->config['count'];
        }

        if(!empty($this->config['host'])){
            if(!is_array($this->config['host'])){
                $this->servers = explode(",", $this->config['host']);
            } else {
                $this->servers = $this->config['host'];
            }
        } else {
            $this->servers = array("127.0.0.1");
        }

        if (!empty($this->config['include']) && $this->config['include'] != "*") {
            $this->config['include'] = explode(",", $this->config['include']);
        } else {
            $this->config['include'] = array();
        }

        if (!empty($this->config['exclude'])) {
            $this->config['exclude'] = explode(",", $this->config['exclude']);
        } else {
            $this->config['exclude'] = array();
        }

        /**
         * Debug option to dump the config and exit
         */
        if(isset($opts["Z"])){
            print_r($this->config);
            exit();
        }

    }


   /**
    *   Opens the logfile.  Will assign to $this->log_file_handle
    *
    *    @param   string    $file     The config filename.
    *
    */
    protected function open_log_file($file) {
        if ($this->log_file_handle) {
            @fclose($this->log_file_handle);
        }
        $this->log_file_handle = @fopen($file, "a");
        if(!$this->log_file_handle){
            $this->show_help("Could not open log file $file");
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

        if (isset($gearman_config[self::DEFAULT_CONFIG])) {
            $this->config = $gearman_config[self::DEFAULT_CONFIG];
            $this->config['functions'] = array();
        }

        foreach($gearman_config as $function=>$data){

            if (strcasecmp($function, self::DEFAULT_CONFIG) != 0) {
                $this->config['functions'][$function] = $data;
            }

        }

    }

    /**
     * Helper function to load and filter worker files
     *
     * return @void
     */
    protected function load_workers() {

        $this->functions = array();

        $dirs = explode(",", $this->worker_dir);

        foreach($dirs as $dir){

            $this->log("Loading workers in ".$dir);

            $worker_files = glob($dir."/*.php");

            if (!empty($worker_files)) {

                foreach($worker_files as $file){

                    $function = substr(basename($file), 0, -4);

                    /**
                     * include workers
                     */
                    if (!empty($this->config['include'])) {
                        if (!in_array($function, $this->config['include'])) {
                            continue;
                        }
                    }

                    /**
                     * exclude workers
                     */
                    if (in_array($function, $this->config['exclude'])) {
                        continue;
                    }

                    if(!isset($this->functions[$function])){
                        $this->functions[$function] = array();
                    }

                    if(!empty($this->config['functions'][$function]['dedicated_only'])){

                        if(empty($this->config['functions'][$function]['dedicated_count'])){
                            $this->log("Invalid configuration for dedicated_count for function $function.", GearmanManager::LOG_LEVEL_PROC_INFO);
                            exit();
                        }

                        $this->functions[$function]['dedicated_only'] = true;
                        $this->functions[$function]["count"] = $this->config['functions'][$function]['dedicated_count'];

                    } else {

                        $min_count = max($this->do_all_count, 1);
                        if(!empty($this->config['functions'][$function]['count'])){
                            $min_count = max($this->config['functions'][$function]['count'], $this->do_all_count);
                        }

                        if(!empty($this->config['functions'][$function]['dedicated_count'])){
                            $ded_count = $this->do_all_count + $this->config['functions'][$function]['dedicated_count'];
                        } elseif(!empty($this->config["dedicated_count"])){
                            $ded_count = $this->do_all_count + $this->config["dedicated_count"];
                        } else {
                            $ded_count = $min_count;
                        }

                        $this->functions[$function]["count"] = max($min_count, $ded_count);

                    }

                    $this->functions[$function]['path'] = $file;

                    /**
                     * Note about priority. This exploits an undocumented feature
                     * of the gearman daemon. This will only work as long as the
                     * current behavior of the daemon remains the same. It is not
                     * a defined part fo the protocol.
                     */
                    if(!empty($this->config['functions'][$function]['priority'])){
                        $priority = max(min(
                            $this->config['functions'][$function]['priority'],
                            self::MAX_PRIORITY), self::MIN_PRIORITY);
                    } else {
                        $priority = 0;
                    }

                    $this->functions[$function]['priority'] = $priority;

                }
            }
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
                $this->parent_pid = $this->pid;
                $this->pid = getmypid();
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
                    pcntl_waitpid($pid, $status, WNOHANG);

                    if (pcntl_wifexited($status) && $status) {
                         $this->log("Child exited with non-zero exit code $status.");
                         exit(1);
                    }

                }
                break;
        }
    }


    /**
     * Forked method that validates the worker code and checks it if desired
     *
     */
    protected function validate_workers(){
        $this->log("Helper forked", GearmanManager::LOG_LEVEL_PROC_INFO);

        $this->load_workers();

        if(empty($this->functions)){
            $this->log("No workers found");
            posix_kill($this->parent_pid, SIGUSR1);
            exit();
        }

        $this->validate_lib_workers();

        /**
         * Since we got here, all must be ok, send a CONTINUE
         */
        posix_kill($this->parent_pid, SIGCONT);

        if($this->check_code){
            $this->log("Running loop to check for new code", self::LOG_LEVEL_DEBUG);
            $last_check_time = 0;
            while(1) {
                $max_time = 0;
                foreach($this->functions as $name => $func){
                    clearstatcache();
                    $mtime = filemtime($func['path']);
                    $max_time = max($max_time, $mtime);
                    $this->log("{$func['path']} - $mtime $last_check_time", self::LOG_LEVEL_CRAZY);
                    if($last_check_time!=0 && $mtime > $last_check_time){
                        $this->log("New code found. Sending SIGHUP", self::LOG_LEVEL_PROC_INFO);
                        posix_kill($this->parent_pid, SIGHUP);
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

            foreach($this->functions as $worker => $settings){
                if(empty($settings["dedicated_only"])){
                    $function_count[$worker] = $this->do_all_count;
                }
            }

        }

        /**
         * Next we loop the workers and ensure we have enough running
         * for each worker
         */
        foreach($this->functions as $worker=>$config) {

            /**
             * If we don't have do_all workers, this won't be set, so we need
             * to init it here
             */
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

        static $all_workers;

        if($worker == "all"){
            if(is_null($all_workers)){
                $all_workers = array();
                foreach($this->functions as $func=>$settings){
                    if(empty($settings["dedicated_only"])){
                        $all_workers[] = $func;
                    }
                }
            }
            $worker_list = $all_workers;
        } else {
            $worker_list = array($worker);
        }

        $pid = pcntl_fork();

        switch($pid) {

            case 0:

                $this->isparent = false;

                $this->register_ticks(false);

                $this->pid = getmypid();

                if(count($worker_list) > 1){

                    // shuffle the list to avoid queue preference
                    shuffle($worker_list);

                    // sort the shuffled array by priority
                    uasort($worker_list, array($this, "sort_priority"));
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
                $this->log("Started child $pid (".implode(",", $worker_list).")", GearmanManager::LOG_LEVEL_PROC_INFO);
                $this->children[$pid] = $worker;
        }

    }

    /**
     * Sorts the function list by priority
     */
    private function sort_priority($a, $b) {
        $func_a = $this->functions[$a];
        $func_b = $this->functions[$b];

        if(!isset($func_a["priority"])){
            $func_a["priority"] = 0;
        }
        if(!isset($func_b["priority"])){
            $func_b["priority"] = 0;
        }
        if ($func_a["priority"] == $func_b["priority"]) {
            return 0;
        }
        return ($func_a["priority"] > $func_b["priority"]) ? -1 : 1;
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
                    if ($this->log_file) {
                        $this->open_log_file($this->log_file);
                    }
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
                list($ts, $ms) = explode(".", sprintf("%f", microtime(true)));
                $ds = date("Y-m-d H:i:s").".".str_pad($ms, 6, 0);
                fwrite($this->log_file_handle, "Date                         PID   Type   Message\n");
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
            list($ts, $ms) = explode(".", sprintf("%f", microtime(true)));
            $ds = date("Y-m-d H:i:s").".".str_pad($ms, 6, 0);
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
        echo "  -l LOG_FILE    Log output to LOG_FILE or use keyword 'syslog' for syslog support\n";
        echo "  -p PREFIX      Optional prefix for functions/classes of PECL workers. PEAR requires a constant be defined in code.\n";
        echo "  -P PID_FILE    File to write process ID out to\n";
        echo "  -u USERNAME    Run wokers as USERNAME\n";
        echo "  -v             Increase verbosity level by one\n";
        echo "  -w DIR         Directory where workers are located, defaults to ./workers. If you are using PECL, you can provide multiple directories separated by a comma.\n";
        echo "  -r NUMBER      Maximum job iterations per worker\n";
        echo "  -x SECONDS     Maximum seconds for a worker to live\n";
        echo "  -Z             Parse the command line and config file then dump it to the screen and exit.\n";
        echo "\n";
        exit();
    }

}

?>
