<?php
/**
 * Redisent, a Redis interface for the modest
 * @author Justin Poliey <jdp34@njit.edu>
 * @copyright 2009 Justin Poliey <jdp34@njit.edu>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package Redisent
 */

!defined('CRLF') && define('CRLF', sprintf('%s%s', chr(13), chr(10)));

if (!class_exists('RedisException', false)) {
    /**
     * Wraps native Redis errors in friendlier PHP exceptions
     */
    class RedisException extends Exception
    {
    }
}

if (!class_exists('Redisent', false)) {
    /**
     * Redisent, a Redis interface for the modest among us
     */
    class Redisent
    {
        /**
         * Socket connection to the Redis server
         * @var resource
         * @access private
         */
        private $__sock;

        /**
         * Host of the Redis server
         * @var string
         * @access public
         */
        public $host;

        /**
         * Port on which the Redis server is running
         * @var integer
         * @access public
         */
        public $port;

        /**
         * Db on which the Redis server is selected
         * @var integer
         * @access public
         */
        public $db;

        /**
         * Flag indicating whether or not commands are being pipelined
         * @var boolean
         * @access private
         */
        private $pipelined = FALSE;

        /**
         * The queue of commands to be sent to the Redis server
         * @var array
         * @access private
         */
        private $queue = array();

        /**
         * Creates a Redisent connection to the Redis server on host {@link $host} and port {@link $port}.
         * @param string  $host     The hostname of the Redis server
         * @param integer $port     The port number of the Redis server
         * @param integer $db       the db to be selected
         * @param bool    $phpredis use phpredis extension or fsockopen to connect to the server
        */
        public function __construct($host, $port = 6379, $db = NULL, $phpredis = true)
        {
            $this->host = $host;
            $this->port = $port;
            $this->db = is_null($db) ? 0 : $db;
            if ($phpredis && !extension_loaded('redis')) {
                $phpredis = false;
            }
            $this->establishConnection2($phpredis);
        }

        public function __destruct()
        {
            $this->close();
        }

        public function close()
        {
            if (!($this->__sock instanceof Redis)) {
                fclose($this->__sock);
            } else {
                $this->__sock->close();
            }
        }

        public function establishConnection()
        {
            $this->__sock = stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr);
            //$this->__sock = fsockopen($this->host, $this->port, $errno, $errstr);
            if (!$this->__sock) {
                throw new Exception("{$errno} - {$errstr}");
            }
            $this->db && $this->select($this->db);
        }

        public function establishConnection2($phpredis = true)
        {
            if ($phpredis) {
                try {
                    $this->__sock = new Redis();
                } catch (Exception $e) {
                    $this->establishConnection();

                    return;
                }
                try {
                    $this->__sock->pconnect($this->host, $this->port);
                    $this->__sock->select($this->db);
                } catch (RedisException $e) {
                    try {
                        $this->__sock->pconnect($this->host, $this->port);
                        $this->__sock->select($this->db);
                    } catch (RedisException $e) {
                        error_log("Redisent establishConnection2 cannot connect to: " . $this->host .':'. $this->port, 0); die();
                    }
                }
            } else {
                $this->establishConnection();

                return;
            }
        }

        /**
         * Returns the Redisent instance ready for pipelining.
         * Redis commands can now be chained, and the array of the responses will be returned when {@link uncork} is called.
         * @see uncork
         * @access public
         */
        public function pipeline()
        {
            $this->pipelined = TRUE;

            return $this;
        }

        /**
         * Flushes the commands in the pipeline queue to Redis and returns the responses.
         * @see pipeline
         * @param string $name redis command being executed
         * @access public
         */
        public function uncork($name = '')
        {
            /* Open a Redis connection and execute the queued commands */
            if (feof($this->__sock)) {
                $this->close();
                $this->establishConnection();
            }

            foreach ($this->queue as $command) {
                for ($written = 0; $written < strlen($command); $written += $fwrite) {
                    $fwrite = fwrite($this->__sock, substr($command, $written));
                    if ($fwrite === FALSE) {
                        throw new RedisException('Failed to write entire command to stream');
                    }
                }
            }

            // Read in the results from the pipelined commands
            $responses = array();
            $cnt = count($this->queue);
            for ($i = 0; $i < $cnt; $i++) {
                if (feof($this->__sock)) {
                    $this->close();
                    $this->establishConnection();
                }
                $responses[] = $this->readResponse($name);
            }

            // Clear the queue and return the response
            $this->queue = array();
            if ($this->pipelined) {
                $this->pipelined = FALSE;

                return $responses;
            } else {
                return $responses[0];
            }
        }

        /**
         * Wrapper of del command to support delete
         * @param $key
         * @return bool
         */
        public function delete($key)
        {
            return $this->__call('del', array($key));
        }

        public function __call($name, $args)
        {
            if (($this->__sock instanceof Redis)) {
                $argcount = count($args);
                if (1 == $argcount) {
                    return $this->__sock->$name($args[0]);
                } elseif (2 == $argcount) {
                    return $this->__sock->$name($args[0], $args[1]);
                } elseif (3 == $argcount) {
                    return $this->__sock->$name($args[0], $args[1], $args[2]);
                } else {
                    return call_user_func_array(array($this->__sock, $name), $args);
                }
            }

            /* Build the Redis unified protocol command */
            array_unshift($args, strtoupper($name));
            $command = sprintf('*%d%s%s%s', count($args), CRLF, implode(array_map(function($arg) {
                return sprintf('$%d%s%s', strlen($arg), CRLF, $arg);
            }, $args), CRLF), CRLF);

                /* Add it to the pipeline queue */
                $this->queue[] = $command;

                if ($this->pipelined) {
                    return $this;
                } else {
                    return $this->uncork($name);
                }
        }

        private function readResponse($name = '')
        {
            /* Parse the response based on the reply identifier */
            $reply = fgets($this->__sock);
            if ($reply === FALSE) {
                throw new RedisException('Lost connection to Redis server.');
            }
            $reply = rtrim($reply, CRLF);
            $replyType = substr($reply, 0, 1);

            //$reply = trim(fgets($this->__sock, 512));
            switch ($replyType) {
                /* Error reply */
                case '-':
                    throw new RedisException(trim(substr($reply, 4)));
                    break;
                    /* Inline reply */
                case '+':
                    $response = substr(trim($reply), 1);
                    if ($response == 'OK' || $response == 'QUEUED') {
                        $response = TRUE;
                    }
                    break;
                    /* Bulk reply */
                case '$':
                    if ($reply == '$-1') {
                        $response = FALSE;
                        break;
                    }
                    $size = (int) substr($reply, 1);
                    $response = stream_get_contents($this->__sock, $size + 2);
                    if(!$response) {
                        throw new RedisException('Error reading reply.');
                    }
                    $response = substr($response, 0, $size);

                    /*$response = NULL;
                     if ($reply == '$-1') {
                    $response = FALSE;
                    break;
                    }
                    $read = 0;
                    $size = (int) substr($reply, 1);
                    if ($size > 0) {
                    do {
                    $block_size = ($size - $read) > 1024 ? 1024 : ($size - $read);
                    $r = fread($this->__sock, $block_size);
                    if ($r === FALSE) {
                    throw new \Exception('Failed to read response from stream');
                    } else {
                    $read += strlen($r);
                    $response .= $r;
                    }
                    } while ($read < $size);
                    }
                    fread($this->__sock, 2); // discard crlf
                    */
                    break;
                    /* Multi-bulk reply */
                case '*':
                    $count = (int) substr($reply, 1);
                    if ($count == '-1') {
                        return NULL;
                    }
                    $response = array();
                    for ($i = 0; $i < $count; $i++) {
                        $response[] = $this->readResponse();
                    }
                    break;
                    /* Integer reply */
                case ':':
                    $response = (int) substr($reply, 1);
                    break;
                default:
                    throw new RedisException("Unknown response: {$reply}");
                    break;
            }

            // Smooth over differences between phpredis and standalone response
            switch ($name) {
                case '': // Minor optimization for multi-bulk replies
                    break;
                case 'config':
                case 'hgetall':
                    $keys = $values = array();
                    while ($response) {
                        $keys[] = array_shift($response);
                        $values[] = array_shift($response);
                    }
                    $response = count($keys) ? array_combine($keys, $values) : array();
                    break;
                case 'info':
                    $lines = explode(CRLF, trim($response,CRLF));
                    $response = array();
                    foreach ($lines as $line) {
                        if (!$line || substr($line, 0, 1) == '#') {
                            continue;
                        }
                        list($key, $value) = explode(':', $line, 2);
                        $response[$key] = $value;
                    }
                    break;
                case 'ttl':
                    if ($response <= 0) {
                        $response = FALSE;
                    }
                    break;
            }

            /* Party on */

            return $response;
        }

    }
}
