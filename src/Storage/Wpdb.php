<?php
declare(strict_types=1);
namespace Tekod\WpQueue\Storage;

use Tekod\WpQueue\Job;


/**
 * Storage driver that uses WordPress's "$wpdb" object to store jobs in database table.
 */
class Wpdb extends AbstractStorage
{

    /**
     * @var \wpdb $wpdb
     */
    protected $wpdb;

    protected $table = '';

    protected $fullTableName = '';

    protected $checkedTable = false;

    protected static $usedTables = [];


    /**
     * Constructor.
     *
     * @param \wpdb $wpdb
     * @param string $table
     */
    public function __construct(\wpdb $wpdb, string $table) {

        // save props
        $this->wpdb = $wpdb;
        $this->table = $table;
        $this->fullTableName = $wpdb->prefix . $table;

        // register used table
        if (isset(self::$usedTables[$table])) {
            throw new \Error(__CLASS__ . ': table "' . $table . '" already registered by another storage driver.');
        }
        self::$usedTables[] = $table;
    }


    /**
     * Store new job.
     *
     * @param string $name
     * @param array  $data
     * @param int    $priority
     * @param int    $runAfter
     * @return bool
     */
    public function add(string $name, array $data, int $priority, int $runAfter): bool {

        $this->maybeCreateTable();

        // store in database
        $values = [
            'Id' => 0,
            'Priority' => $priority,
            'RunAfter' => $runAfter ? gmdate('Y-m-d H:i:s', $runAfter) : '',
            'ClaimedBy' => '',
            'ClaimedAt' => '',
            'FailCount' => 0,
            'JobName' => $name,
            'JobData' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ];
        $success = $this->wpdb->insert($this->fullTableName, $values);

        // return boolean
        return $success !== false && $success !== 0;
    }


    /**
     * Find number of unprocessed jobs in queue.
     *
     * @param null|string $jobName  specify name of job to get count of that jobs
     * @param bool        $includingDeferred  whether to include jobs scheduled in future or only currently available
     * @return int
     */
    public function getCount(?string $jobName=null, bool $includingDeferred=false): int {

        return count($this->fetch('', $jobName, $includingDeferred));
    }


    /**
     * Return list of unprocessed jobs in queue, without claiming or any other side effect.
     *
     * @param null|string $jobName  specify name of job to get list of that jobs
     * @param bool        $includingDeferred  whether to include jobs scheduled in future or only currently available
     * @return array
     */
    public function getList(?string $jobName='', bool $includingDeferred=false): array {

        $records =  $this->fetch('', $jobName, $includingDeferred);
        return $this->instantiateJobObjects($records);
    }


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
    public function claim(?string $jobName=null, bool $singleJob=true): array {

        $this->maybeCreateTable();

        // prepare query
        // sorting by FailCount will defer execution of already faulted jobs for as much as possible later
        $table = $this->fullTableName;
        $set = 'ClaimedBy=%s, ClaimedAt=%s'; // claim ownership
        $where = "RunAfter < %s AND ClaimedBy = ''";  // job must be available and unclaimed
        $orderBy = 'Priority,FailCount,Id'; // mostly prioritized first, then least-faulted and then oldest one
        $limit = $singleJob ? 'LIMIT 1' : ''; // only one record?
        $args = [
            $this->queue->getInstanceId(),
            date('Y-m-d H:i:s', time()),
            date('Y-m-d H:i:s', time()),
        ];

        // search for specified job name
        if ($jobName) {
            $where .= ' AND JobName=%s';
            $args[] = $jobName;
        }

        // execute locking
        $result = $this->wpdb->query($this->wpdb->prepare("UPDATE $table SET $set WHERE $where ORDER BY $orderBy $limit", $args));
        if (intval($result) < 1) {
            return [];  // nothing locked
        }

        // find locked records
        $records = $this->fetch($this->queue->getInstanceId(), null, false);

        // return job objects
        return $this->instantiateJobObjects($records);
    }


    /**
     * Removes specified job from queue completely.
     *
     * @param int $id
     */
    public function delete(int $id): void {

        $this->wpdb->delete($this->fullTableName, ['Id' => $id], ['%d']);
    }


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
    public function release(int $id, int $failCount, bool $incFailCount=true, ?int $runAfter=null): void {

        // prepare query
        $values = [
            'ClaimedBy' => '',
            'ClaimedAt' => '',
        ];
        $formatValues = ['%s', '%s'];
        if ($incFailCount) {
            $values['FailCount'] = $failCount + 1;
            $formatValues[] = '%d';
        }
        if ($runAfter) {
            $values['RunAfter'] = date('Y-m-d H:i:s', time());
            $formatValues[] = '%s';
        }

        // execute query
        $this->wpdb->update($this->fullTableName, $values, ['Id' => $id], $formatValues, ['%d']);
    }


    /**
     * Remove everything from queue, even jobs currently processing.
     */
    public function clear(): void {

        $this->maybeCreateTable();
        $this->wpdb->query("TRUNCATE $this->fullTableName");
    }


    /**
     * Load available jobs from database and return list of raw records.
     * If you pass empty string for $owner it will return list of unclaimed jobs, otherwise it will search for jobs of that owner.
     *
     * @param string      $owner
     * @param string|null $jobName
     * @param bool        $includingDeferred
     * @return array
     */
    protected function fetch(string $owner, ?string $jobName, bool $includingDeferred): array {

        $this->maybeCreateTable();

        // prepare query
        $table = $this->fullTableName;
        $where = 'ClaimedBy=%s';
        $args = [
            $owner,
        ];

        // only for specified job name
        if ($jobName) {
            $where .= ' AND JobName=%s';
            $args[] = $jobName;
        }

        // only currently available jobs
        if (!$includingDeferred) {
            $where .= ' AND RunAfter <= %s';
            $args[] = date('Y-m-d H:i:s', time());
        }

        // execute query
        $results = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY Id", $args), ARRAY_A);

        // ret
        return $results;
    }


    /**
     * Convert list or records to list of Job objects.
     *
     * @param array $records
     * @return array
     */
    protected function instantiateJobObjects(array $records): array {

        foreach ($records as &$val) {
            // unpack data
            $data = json_decode($val['JobData'], true, 128, JSON_BIGINT_AS_STRING);
            // create object
            $val = new Job(intval($val['Id']), $val['JobName'], $data, intval($val['FailCount']), $this->queue);
        }
        return $records;
    }


    /**
     * Helper method: create database table if necessary.
     */
    protected function maybeCreateTable(): void {

        // skip if already checked
        if ($this->checkedTable) {
            return;
        }

        // check existence
        $this->checkedTable = true;
        $tables = $this->wpdb->get_results('SHOW TABLES', ARRAY_A);
        foreach ($tables as $table) {
            if (end($table) === $this->fullTableName) {
                return;
            }
        }

        // create database table
        $sql = "
            CREATE TABLE $this->fullTableName (
				Id bigint(20) NOT NULL AUTO_INCREMENT,
				Priority int NOT NULL,
				RunAfter datetime NOT NULL,
				ClaimedBy varchar(8) NOT NULL,
				ClaimedAt datetime NOT NULL,
				FailCount int NOT NULL,
				JobName varchar(32),
				JobData longtext,
				PRIMARY KEY (Id)
			)" . $this->wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

}
