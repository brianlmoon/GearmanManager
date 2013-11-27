<?php

/**
 * Worker interface
 *
 * @author Mikhail Yurasov <me@yurasov.me>
 */
interface GearmanManagerWorkerInterface
{
    /**
     * Run task
     *
     * @param \GearmanJob $job
     * @param array $log
     */
    public function run(\GearmanJob $job, &$log);
}
