Gearman Manager for PHP
=======================

PHP Requirements
----------------

 * PHP 5.2.? - not sure exact version
 * POSIX extension
 * Process Control extension
 * pecl/gearman or Net_Gearman

Why use GearmanManager
======================

Running Gearman workers can be a tedious task. Many many files with the same lines of code over and over for creating a worker, connecting to a server, adding functions to the worker, etc. etc. The aim of GearmanManager is to make running workers more of an operational task and less of a development task.

The basic idea is that once deployed, all you need to do is write the code that actually does the work and not all the repetative worker setup. We do this by creating files that contain functions in a specified directory. The file names determine what functions are registered with the gearmand server. This greatly simplifies the function registration.

How it works
============

We start by deciding where our worker code will live. For this example, lets say we create a directory called worker_dir to hold all our worker code. We would then create files like these: (These examples use pecl/gearman syntax. For PEAR/Gearman see the example pear workers for minor differences)

Procedural:

    # cat worker_dir/example_function.php

    function example_function($job, &$log) {

        $workload = $job->workload();

        // do work on $job here as documented in pecl/gearman docs

        // Log is an array that is passed in by reference that can be
        // added to for logging data that is not part of the return data
        $log[] = "Success";

        // return your result for the client
        return $result;

    }

The existence of this code would register a function named example_function with the gearmand server.

Object Oriented:

    # cat worker_dir/ExampleFunction.php

    class ExampleFunction {

        public function run($job, &$log) {

            $workload = $job->workload();

            // do work on $job here as documented in pecl/gearman docs

            // Log is an array that is passed in by reference that can be
            // added to for logging data that is not part of the return data
            $log[] = "Success";

            // return your result for the client
            return $result;

        }

    }

The existence of this code would register a function named ExampleFunction with the gearmand server.

But wait! There's more
======================

In addition to making worker creation easier, GearmanManager also provides process management. If a process dies, it restarts a new one in its place. You can also configure workers to die after a certain amount of time to prevent PHP from using too much memory.

Then there is the problem of pushing new code out to the servers. GearmanManager has an option to have it monitor the worker dir and restart the workers if it sees new code has been deployed to the server.

When shutting down GearmanManager, it will allow the worker processes to finish their work before exiting.

Advanced Stuff
==============

While it easy to get going, you can do some advanced things with GearmanManager.

Configuring Workers
-------------------

By default, GearmanManager ensures that there is at least one worker that knows how to do every job that has a file in the worker directory. To be clear, that means that by default a single process will be created for every function. This is obviously not ideal for most production systems. There are a few ways you can customize how many processes run for the functions.

The ini file for GearmanManager is divided into one or more sections. There is the global section [GearmanManager] and there can be one section per function. In the example above that would be [example_function] or [ExampleFunction]. For the global section there are several options. There are a couple of sample ini files in the code.

worker_dir - Defines the directory(s) where worker functions live. You can specify multiple directories by separating them with commas.

include - This is a list of worker functions that should be registered by this server. It can be * to include all functions defined in the worker directory. * is the default behavior if the option is not set.

count - This setting defines the minimum number of workers that should be running that will perform all functions. For example, if count is set to 10, 10 processes will be started that are registered for all the functions. This defaults to 0.

dedicated_count - This setting defines the number of processes that should be started that will be dedicated to do only one function for each function that will be registered. For example, if you have 5 functions in your worker directory and set dedicated_count = 2 there will be 2 processes started for each function, so 10 total processes for this work. This defaults to 1.

max_worker_lifetime - Set this value to the maximum number of seconds you want a worker to live before it dies. After finishing a job, the worker will check if it has been running past the max life time and if so, it will exit. The manager process will then replace it with a new worker that is registered to do the same jobs that the exiting process was doing. This defaults to 1 hour.

auto_update - If set to 1, the manager will fork a helper process that watches the worker directory for changes. If new code is found, a signal is sent to the parent process to kill the worker processes so that the new code can be loaded.

For each registered function, you can also specify some options for only those workers.

count - Setting this to some integer value will ensure there are that many workers that know how to do this function. It does not guarantee there will be a dedicated process, only that some process will register this function. The process may also register other functions as well.

dedicated_count - Setting this to some integer value will ensure there are that many processes started that are dedicated to only doing this function. The process will not do any other work than this function.

Logging Data
------------

There are lots of options on the command line. See -h for all of them. Logging is one of those options. Because the loading of the config is logged, you have to specify logging information on the command line. There are two options you should be aware off.

 -v  This enables verbosity from the manager and workers. You can add more and more and more v's (-vvvv) to get more and more logged data. It scales up like this:

    -v      Logs only information about the start up and shutdown
    -vv     Logs information about process creation and exiting
    -vvv    Logs information about workers and the work they are doing
    -vvvv   Logs debug information
    -vvvvv  Logs crazy amounts of data about all manner of things

 -l  You can specify a log file that log data will be sent to. If not set, log data will be sent to stdout. You can also set this to syslog to have the log data sent to syslog.

Specifying Gearmand Servers
---------------------------

You can specify servers in two ways. They can be specified on the command line or in the configuration file.

On the command line, you can specify -h [HOST[:PORT][,[HOST[:PORT]]]]. For example: -h 10.1.1.1:4730,10.1.1.2:4730

In the config file in the global [GearmanManager] section you can use these options:

host - The hosts and ports separated by commas. e.g. 10.1.1.1:4730,10.1.1.2:4730

Running the daemon
------------------

Minimal command line is:

    # ./pecl-manager.php -c /path/to/config.ini

There are some more command line options that are handy for running the daemon.

-P - The location where the pid file should be stored for the manager process. Also setable with pid_file in the ini file.

-d - If set on the command line, the manager will daemonize

-u - The user to run the daemon as. You can also use user in the ini file.

Debugging
=========

So you built an awesome worker but for some reason it dies and the `error_log` is empty?

GearmanManager makes use of the supression operator (`@`) every now and then which makes it a little harder to debug because error messages are silenced.

The solution is [Xdebug](http://xdebug.org/)'s scream and the steps to set it up are:

 1. Install Xdebug
 2. Configure it
 3. Profit!


Installation
------------

    pecl install xdebug

Configuration
-------------

Put the following into your `xdebug.ini`:

    zend_extension="/path/to/where/your/xdebug.so"
    xdebug.scream = 1
    xdebug.show_exception_trace = 1

