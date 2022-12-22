<?php
declare(strict_types=1);
namespace Tekod\WpQueue\Storage;

use Tekod\WpQueue\Queue;


/**
 * Base class for all storage drivers.
 */
abstract class AbstractStorage
{

    /**
     * Pointer to parent queue.
     *
     * @var Queue $queue
     */
    protected $queue;


    /**
     * Store new job.
     *
     * @param string $name
     * @param array  $data
     * @param int    $priority
     * @param int    $runAfter
     * @return bool
     */
    abstract public function add(string $name, array $data, int $priority, int $runAfter): bool;


    /**
     * Find number of unprocessed jobs in queue.
     *
     * @param null|string $jobName  specify name of job to get count of that jobs
     * @param bool        $includingDeferred  whether to include jobs scheduled in future or only currently available
     * @return int
     */
    abstract public function getCount(?string $jobName=null, bool $includingDeferred=false): int;


    /**
     * Return list of unprocessed jobs in queue, without claiming or any other side effect.
     *
     * @param null|string $jobName  specify name of job to get list of that jobs
     * @param bool        $includingDeferred  whether to include jobs scheduled in future or only currently available
     * @return array
     */
    abstract public function getList(?string $jobName='', bool $includingDeferred=false): array;


    /**
     * Query storage and find available job(s).
     * Selected jobs will remain on storage but locked (claimed) to prevent concurrent workers to mess with them.
     * Later on application must either release() or delete() reserved jobs.
     * Return value is an array of all claimed records.
     *
     * @param null|string $jobName  identifier of job used to find appropriate job processor or all jobs
     * @param bool        $singleJob  find and return only first job in queue or all available jobs
     * @return array
     */
    abstract public function claim(?string $jobName=null, bool $singleJob=true): array;


    /**
     * Removes specified job from queue completely.
     *
     * @param int $id
     */
    abstract public function delete(int $id): void;


    /**
     * Opposite of claim() method, removes identifier from specified job making it available for claiming by other workers.
     * Optionally this will increment fail-counter.
     * Next execution can be delayed (cooldown) by specifying RunAfter timestamp in the future.
     *
     * @param int      $id  id of current job
     * @param int      $failCount  number of failures
     * @param bool     $incFailCount  allow incrementing fail-counter
     * @param null|int $runAfter  timestamp
     */
    abstract public function release(int $id, int $failCount, bool $incFailCount=true, ?int $runAfter=null): void;


    /**
     * Remove everything from queue, even jobs currently processing.
     */
    abstract public function clear(): void;


    /**
     * Connect this storage driver with parent queue.
     *
     * @param Queue $queue
     */
    public function setQueue(Queue $queue): void {

        $this->queue = $queue;
    }

}
