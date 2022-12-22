<?php
declare(strict_types=1);
namespace Tekod\WpQueue;


/**
 * Class Job represents DTO object of single queue job.
 */
class Job
{

    protected $id;

    protected $name;

    protected $data;

    protected $failCount;

    protected $queue;

    protected $handled = false;

    protected $released = false;


    /**
     * Constructor.
     *
     * @param int $id
     * @param string $name
     * @param array $data
     * @param int $failCount
     * @param Queue $queue
     */
    public function __construct(int $id, string $name, array $data, int $failCount, Queue $queue) {

        $this->id = $id;
        $this->name = $name;
        $this->data = $data;
        $this->failCount = $failCount;
        $this->queue = $queue;
    }


    /**
     * Return ID of job.
     *
     * @return int
     */
    public function getId(): int {

        return $this->id;
    }


    /**
     * Return name of job.
     *
     * @return string
     */
    public function getName(): string {

        return $this->name;
    }


    /**
     * Getter of job record data.
     *
     * @return mixed
     */
    public function getData() {

        return $this->data;
    }


    /**
     * Setter of job record data.
     *
     * @param mixed $data  payload of data
     */
    public function setData($data): void {

        $this->data = $data;
    }


    /**
     * Return number of failures.
     *
     * @return int
     */
    public function getFailCount(): int {

        return $this->failCount;
    }


    /**
     * Return current queue object.
     *
     * @return Queue
     */
    public function getQueue(): Queue {

        return $this->queue;
    }


    /**
     * Fetch flag "handled".
     *
     * @return bool
     */
    public function getHandled(): bool {

        return $this->handled;
    }


    /**
     * Set flag "handled".
     *
     * @param bool $status
     */
    public function setHandled(bool $status = true): void {

        $this->handled = $status;
    }


    /**
     * Set flag "released", and logically affecting also "handled".
     *
     * @param bool     $status
     * @param bool     $incFailCount  increment count of failed attempts
     * @param null|int $runAfter  schedule time of next attempt
     */
    public function setReleased(bool $status = true, bool $incFailCount = true, ?int $runAfter = null): void {

        $this->setHandled($status); // release affects "handled" too

        $this->released = $status
            ? [$incFailCount, $runAfter]
            : false;
    }


    /**
     * Fetch flag "released".
     *
     * @return false|array
     */
    public function getReleased() {

        return $this->released;
    }

}
