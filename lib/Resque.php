<?php
include_once dirname(__FILE__) . '/Resque/Event.php';
include_once dirname(__FILE__) . '/Resque/Exception.php';

/**
 * Base Resque class.
 *
 * @package		Resque
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque
{
    const VERSION = '1.2';

    /**
     * @var Resque_Redis Instance of Resque_Redis that talks to redis.
     */
    public static $redis = null;

    /**
     * @var mixed Host/port conbination separated by a colon, or a nested
     * array of server swith host/port pairs
     */
    protected static $redisServer = null;

    /**
     * @var int ID of Redis database to select.
     */
    protected static $redisDatabase = 0;

    /**
     * @var bool use phpredis extension or fsockopen to connect to the redis server
     */
    public static $phpredis = null;

    /**
     * Given a host/port combination separated by a colon, set it as
     * the redis server that Resque will talk to.
     *
     * @param mixed $server Host/port combination separated by a colon, or
     * a nested array of servers with host/port pairs.
     * @param integer $database the db to be selected
     * @param bool    $phpredis use phpredis extension or fsockopen to connect to the server
     */
    public static function setBackend($server, $database = 0, $phpredis = true)
    {
        self::$redisServer   = $server;
        self::$redisDatabase = $database;
        self::$phpredis      = $phpredis;
        self::$redis         = null;

        return self::redis();
    }

    /**
     * Return an instance of the Resque_Redis class instantiated for Resque.
     *
     * @return Resque_Redis Instance of Resque_Redis.
     */
    public static function redis()
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        $server = self::$redisServer;
        if (empty($server)) {
            $server = 'localhost:6379';
        }

        if (is_array($server)) {
            include_once dirname(__FILE__) . '/Resque/RedisCluster.php';
            self::$redis = new Resque_RedisCluster($server, self::$redisDatabase, self::phpredis);
        } else {
            if (strpos($server, 'unix:') === false) {
                list($host, $port) = explode(':', $server);
            } else {
                $host = $server;
                $port = null;
            }
            include_once dirname(__FILE__) . '/Resque/Redis.php';
            self::$redis = new Resque_Redis($host, $port, self::$redisDatabase, self::$phpredis);
        }

        return self::$redis;
    }

    /**
     * fork() helper method for php-resque that handles issues PHP socket
     * and phpredis have with passing around sockets between child/parent
     * processes.
     *
     * Will close connection to Redis before forking.
     *
     * @return int Return vars as per pcntl_fork()
     */
    public static function fork()
    {
        if (!function_exists('pcntl_fork')) {
            return -1;
        }

        // Close the connection to Redis before forking.
        // This is a workaround for issues phpredis has.
        self::$redis = null;

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork child worker.');
        }

        return $pid;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param array  $item  Job description as an array to be JSON encoded.
     */
    public static function push($queue, $item)
    {
        self::redis()->sadd('queues', $queue);

        return (int) self::redis()->rpush('queue:' . $queue, json_encode($item));
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param  string $queue The name of the queue to fetch an item from.
     * @return array  Decoded item from the queue.
     */
    public static function pop($queue)
    {
        $item = self::redis()->lpop('queue:' . $queue);
        if (!$item) {
            return;
        }

        return json_decode($item, true);
    }

    /**
     * Return the size (number of pending jobs) of the specified queue.
     *
     * @param $queue name of the queue to be checked for pending jobs
     *
     * @return int The size of the queue.
     */
    public static function size($queue)
    {
        return self::redis()->llen('queue:' . $queue);
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string  $queue       The name of the queue to place the job in.
     * @param string  $class       The name of the class that contains the code to execute the job.
     * @param array   $args        Any optional arguments that should be passed when the job is executed.
     * @param boolean $trackStatus Set to true to be able to monitor the status of a job.
     *
     * @return string
     */
    public static function enqueue($queue, $class, $args = null, $trackStatus = false)
    {
        include_once dirname(__FILE__) . '/Resque/Job.php';
        $result = Resque_Job::create($queue, $class, $args, $trackStatus);
        if ($result) {
            Resque_Event::trigger('afterEnqueue', array(
            'class' => $class,
            'args'  => $args,
            'queue' => $queue,
            ));
        }

        return $result;
    }

    /**
     * Reserve and return the next available job in the specified queue.
     *
     * @param  string     $queue Queue to fetch next available job from.
     * @return Resque_Job Instance of Resque_Job to be processed, false if none or error.
     */
    public static function reserve($queue)
    {
        include_once dirname(__FILE__) . '/Resque/Job.php';

        return Resque_Job::reserve($queue);
    }

    /**
     * Get an array of all known queues.
     *
     * @return array Array of queues.
     */
    public static function queues()
    {
        $queues = self::redis()->smembers('queues');
        if (!is_array($queues)) {
            $queues = array();
        }

        return $queues;
    }
}
