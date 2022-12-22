<?php
declare(strict_types=1);
namespace Tekod\WpQueue;


use Tekod\WpQueue\Storage\AbstractStorage;

/**
 * Queue manager and worker.
 */
class Queue {

    protected $defaultConfig = [

        // instance of queue-storage driver
        'Storage' => null,

        // how many times to repeat execution of failed job before give-up
        'MaxFails' => 3,

        // increase PHP's allowed execution time at beginning of job processing, in seconds
        'SetTimeLimit' => 600,  // remember: this is the only way to terminate hanged script

        // should worker execute all jobs in loop or only first available
        'Loop' => true,

        // specify maximum size of log-file
        'LogSizeLimit' => 2 << 22,  // 4Mb

        // specify time zone for log entries
        'TimeZone' => 'Europe/Belgrade',
    ];

    /** @var Storage\AbstractStorage $storage */
    protected $storage;

    protected $instanceName;

    protected $instanceId;

    protected $config;

    protected $handlers = [];

    protected $currentJob = false;

    protected $jobCounter = 0;

    protected $logPath;

    protected $startTime;

    protected $memoryLimit;

    protected $processJobName;


    /**
     * Constructor.
     *
     * @param string $name
     */
    public function __construct(string $name)
    {
        global $timestart; // variable of WordPress metrics from "/wp-includes/load.php"
        $this->setInstanceId();
        $this->instanceName = $name;
        $this->startTime = intval($timestart); // time();
        $this->memoryLimit = intval($this->getPhpMemoryLimit() * 0.9);
    }


    /**
     * Setup configuration.
     *
     * @param array $config
     * @return self
     */
    public function init(array $config): self {

        $this->config = $config + $this->defaultConfig;
        $this->storage = $this->config['Storage'];
        $this->storage->setQueue($this);
        $this->prepareLogFile();
        return $this;
    }


    /**
     * Generate random 8-char string and set it as unique instance identifier.
     */
    protected function setInstanceId(): void {

        // there is no need for high quality random generator, this will be fine...
        $this->instanceId = substr(md5(mt_rand(0, 999999) . uniqid() . microtime(false)), 0, 8);
    }


    /**
     * Returns unique identifier of this queue.
     *
     * @return string
     */
    public function getInstanceId(): string {

        return $this->instanceId;
    }


    /**
     * Return PHP configuration for maximum memory size.
     *
     * @return int
     */
    protected function getPhpMemoryLimit(): int {

        $memoryLimit = ini_get('memory_limit');
        // resolve value with "M" suffix
        if (preg_match('#([0-9]+) ?M#i', $memoryLimit, $matches)) {
            return $matches[1] * 1024 * 1024;
        }
        // resolve value with "G" suffix
        if (preg_match('#([0-9]+) ?G#i', $memoryLimit, $matches)) {
            return $matches[1] * 1024 * 1024 * 1024;
        }
        return intval($memoryLimit);
    }


    /**
     * Store new job of given name and associated data.
     *
     * @param string $name
     * @param array  $data
     * @param int $priority
     * @param int $runAfter
     * @return bool
     */
    public function add(string $name, array $data, int $priority = 10, int $runAfter = 0): bool {

        $this->log('Added "' . $name . '" job.');
        return $this->storage->add($name, $data, $priority, $runAfter);
    }


    /**
     * Return number of unprocessed jobs in queue.
     *
     * @param null|string $jobName  specify name of job to get count of that jobs
     * @param bool        $includingDeferred  whether to include jobs scheduled in future or only currently available
     * @return int
     */
    public function count(?string $jobName=null, bool $includingDeferred=false): int
    {
        return $this->storage->getCount($jobName);
    }


    /**
     * Query queue storage and find available job(s).
     * Selected jobs will remain on storage but locked (claimed) to prevent concurrent workers to mess with them.
     * Later on worker will either Release() or Delete() reserved jobs.
     * Return value is an array of all claimed records.
     * Job processor can call this method to load additional jobs to process all of them in one go
     * (for example: bulk email sending) but it must manually delete/release them at finnish.
     *
     * @param null|string $jobName  identifier of job used to find appropriate job processor or all jobs
     * @param bool        $singleJob  find and return only first job in queue or all available jobs
     * @return array
     */
    public function claim(?string $jobName = null, bool $singleJob = true): array {

        do {
            // load from storage
            $jobs = $this->storage->claim($jobName, $singleJob);

            // remove jobs exceeding fail limit
            $removed = false;
            foreach ($jobs as $key => $job) {
                if ($job->getFailCount() > $this->config['MaxFails']) {
                    $this->tooManyFails($job);
                    $removed = true;
                    unset($jobs[$key]);
                }
            }

            // try again if only one job requested and it was removed
        } while ($singleJob && $removed);

        // return array of jobs
        return $jobs;
    }


    // public function delete($id)


    /**
     * Unclaim specified job.
     * Note that worker will unclaim all failed jobs by itself, there is no need to explicitly call this from job processor.
     *
     * @param Job       $job
     * @param bool      $incFailCount
     * @param bool|null $runAfter
     */
    public function release(Job $job, bool $incFailCount=true, bool $runAfter=null): void {

        $this->storage->release($job->getId(), $job->getFailCount(), $incFailCount, $runAfter);
    }


    /**
     * Remove everything from queue.
     */
    public function clear(): void {

        $this->storage->clear();
        $this->log('Cleared.');
    }


    /**
     * In case of fatal error or timeout automatically unclaim current job so next tick can take ownership and try again.
     */
    public function onShutdown(): void {

        // skip on clean exit
        if ($this->currentJob === false) {
            return;
        }

        // unclaim current job and increment fail counter
        $this->release($this->currentJob, true, null);

        // log this
        $this->log("Worker loop unexpectedly terminated (OnShutdown), last error:\n" . var_export(error_get_last(), true));

        // clear job
        $this->currentJob = false;
    }


    /**
     * Execute tasks in query.
     *
     * @param string|null $name
     * @return bool|null  status of last executed job
     */
    public function run(?string $name = null): ?bool {

        // adjust environment
        wp_raise_memory_limit('admin');  // maximize memory pool
        ignore_user_abort(true);  // make it immune to closing browser
        set_time_limit($this->config['SetTimeLimit']);  // extend timeout (default 10 minutes)

        // log
        $msg = "Starting new queue worker: Name=%s, MemLimit=%s, StartTime=%s, TTL=%s, SpentTTL=%s.";
        $args = [$name ?? '-', $this->memoryLimit, $this->startTime, $this->config['SetTimeLimit'], time() - $this->startTime];
        $this->log(vsprintf($msg, $args));

        // preset variables
        $this->processJobName = $name;
        $lastStatus = false;
        $this->jobCounter = 0;

        // hook on shutdown
        register_shutdown_function([$this, 'OnShutdown']);

        // notify listeners to register all job handlers
        do_action('wp-queue.register-handlers', $this);

        // start infinite loop
        do {
            // this is long term loop, allow PHP to do some internal housekeeping
            gc_collect_cycles();

            // is there any reason to terminate loop?
            if ($this->terminateLoop()) {
                break;  // jump out
            }

            // fetch next available job
            if (!$this->getNextJob()) {
                break;  // jump out
            }

            // execute job
            $lastStatus = $this->executeJob();

            // break if configured
        } while ($this->config['Loop']);

        // finish
        $this->log("Queue worker finished. $this->jobCounter jobs processed.");
        $this->currentJob = false; // inform shutdown function about clean exit
        return $lastStatus;
    }


    /**
     * Check is there any reason to terminate worker loop.
     *
     * @return bool
     */
    protected function terminateLoop(): bool {

        // set_time_limit() will cause breaking PHP after TTL, it is better to nicely finish script before that happen
        if (time() - $this->startTime > $this->config['SetTimeLimit']) {
            $this->log('Queue worker TTL is exceeded, terminating loop.');
            return true;
        }

        // terminate if we are close to memory limit
        if (memory_get_usage(true) > $this->memoryLimit) {
            $this->log('Queue loop is terminated by reaching memory limit of ' . number_format($this->memoryLimit) . ' bytes.');
            return true;
        }

        // allow event listeners to terminate loop
        if (apply_filters('wp-queue.loop', false, $this)) {
            $this->log('Queue loop is terminated by event listener.');
            return true;
        }

        // continue loop
        return false;
    }


    /**
     * Fetch one job from the storage.
     *
     * @return bool|Job
     */
    protected function getNextJob() { // phpcs:ignore Inpsyde.CodeQuality.ReturnTypeDeclaration

        // get single job from driver
        $jobs = $this->claim($this->processJobName);

        // no more jobs?
        if (empty($jobs)) {
            if ($this->jobCounter > 0) {
                $this->log('No more jobs.');
            }
            return false;
        }

        // assign to current job
        $this->currentJob = $jobs[0];
        $this->jobCounter++;

        // success
        return true;
    }


    /**
     * Preform execution of current job.
     *
     * @return null|bool  true=success, false=job was released, null=job was unhandled
     */
    protected function executeJob(): ?bool {

        // call handlers to handle this job
        $jobId = $this->currentJob->getId();
        $jobName = $this->currentJob->getName();
        $handlersByPriority = $this->handlers[$jobName] ?? [];
        foreach ($handlersByPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                if (!$this->currentJob->getHandled()) {
                    $callback($this->currentJob);
                }
            }
        }

        // if nobody handles that job trigger orphan event
        // this is last chance to process this job
        if (!$this->currentJob->getHandled()) {
            do_action('wp-queue.orphan-job', $this->currentJob);
        }

        // if job remains unhandled
        if (!$this->currentJob->getHandled()) {
            $this->log("Unhandled job #$jobId ($jobName)");
            $this->unhandledJob($this->currentJob);
            return null;
        }

        // if job has to be released
        if ($this->currentJob->getReleased()) {
            // unclaim it and increment fail counter
            [$incFailCount, $runAfter] = $this->currentJob->getReleased();
            $this->release($this->currentJob, $incFailCount, $runAfter);
            do_action('wp-queue.released-job', $this->currentJob);
            $this->log("Released job #$jobId.");
            return false;
        }

        // job was executed successfully, remove it from queue
        do_action('wp-queue.executed-job', $this->currentJob);
        $this->log("Handled job #$jobId.");
        $this->storage->delete($jobId);
        return true;
    }


    /**
     * Perform specific action if job fails too many times.
     *
     * @param Job $job
     */
    protected function tooManyFails(Job $job): void {

        // fire event, listeners can:
        //  - move record to separate "queue_failed" table
        //  - write log message about what happen
        //  - send email message
        //  - add this job to storage again but with much bigger RunAfter and delete current one
        //  - return false to prevent record deletion
        if (apply_filters('wp-queue.too-many-fails', true, $job)) {
            // by default simply remove it from storage
            $this->storage->delete($job->getId());
        }
    }


    /**
     * Perform specific action if nobody takes responsibility for this job.
     *
     * @param Job $job
     */
    protected function unhandledJob(Job $job): void {

        // fire event, listeners can:
        //  - move record to separate "queue_failed" table
        //  - write log message about what happen
        //  - send email message
        //  - return false to prevent record deletion
        if (apply_filters('wp-queue.unhandled-job', true, $job)) {
            // by default simply remove it from storage
            $this->storage->delete($job->getId());
        }
    }


    /**
     * Specify what method to call to handle job of given name.
     *
     * @param string $name
     * @param string|array|\Closure $callable
     * @param int $priority
     * @return void
     */
    public function registerJobHandler(string $name, $callable, int $priority = 10): void {

        $this->handlers[$name][$priority][] = $callable;
    }


    /**
     * Initialize logging system.
     */
    protected function prepareLogFile(): void {

        // calc path
        $dir = wp_get_upload_dir()['basedir'] . '/logs';
        $this->logPath = "$dir/WpQueue.log";

        // ensure directory existence
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            file_put_contents("$dir/.htaccess", 'deny from all');
        }

        // ensure logfile existence
        touch($this->logPath);

        // trim log file if it became too big
        if (filesize($this->logPath) > $this->config['LogSizeLimit']) {
            $dump = '  .  .  .  . . . ......'
                . file_get_contents($this->logPath, false, null, -intval(($this->config['LogSizeLimit'] * 0.9)));
            file_put_contents($this->logPath, $dump);
        }
    }


    /**
     * Store log messages.
     *
     * @param string $message
     */
    public function log(string $message): void {

        $now = new \DateTime('now', new \DateTimeZone($this->config['TimeZone']));
        $entry = "\r\n\r\n" . $now->format('Y-m-d H:i:s') . " [$this->instanceName]  $message";
        file_put_contents($this->logPath, $entry, FILE_APPEND);
    }

}
