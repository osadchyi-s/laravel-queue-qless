<?php

namespace LaravelQless\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\Queue;
use LaravelQless\Contracts\JobHandler;
use LaravelQless\Job\AbstractJob;
use LaravelQless\Job\QlessJob;
use Qless\Client;
use Qless\Jobs\BaseJob;
use Qless\Topics\Topic;

/**
 * Class QlessQueue
 * @package LaravelQless\Queue
 */
class QlessQueue extends Queue implements QueueContract
{
    public const JOB_OPTIONS_KEY = '__QLESS_OPTIONS';

    private const WORKER_PREFIX = 'laravel_';

    /**
     * @var Client
     */
    private $connect;

    /**
     * @var string
     */
    private $defaultQueue;

    /**
     * @var array
     */
    private $config;

    /**
     * QlessQueue constructor.
     * @param Client $connect
     * @param array $config
     */
    public function __construct(Client $connect, array $config)
    {
        $this->connect = $connect;
        $this->defaultQueue = $config['queue'] ?? null;
        $this->connectionName = $config['connection'] ?? '';
        $this->config = $config;
    }

    /**
     * Get the size of the queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function size($queue = null): int
    {
        return $this->getConnection()->length($queue ?? '');
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string  $queueName
     * @param  array   $options
     * @return mixed
     */
    public function pushRaw($payload, $queueName = null, array $options = [])
    {
        $payloadData = array_merge(json_decode($payload, true), $options);

        $queueName = $queueName ?? $this->defaultQueue;

        $queue = $this->getConnection()->queues[$queueName];

        $qlessOptions = $payloadData['data'][self::JOB_OPTIONS_KEY] ?? [];

        $options = array_merge($qlessOptions, $options);

        return $queue->put(
            $payloadData['job'],
            $payloadData['data'],
            $options['jid'] ?? null,
            $options['delay'] ?? null,
            $options['retries'] ?? null,
            $options['priority'] ?? null,
            $options['tags'] ?? null,
            $options['depends'] ?? null
        );
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed   $data
     * @param  string  $queueName
     * @return mixed
     */
    public function push($job, $data = '', $queueName = null)
    {
        return $this->pushRaw($this->makePayload($job, (array) $data), $queueName);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed   $data
     * @param  string  $queueName
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queueName = null)
    {
        $options = $data[self::JOB_OPTIONS_KEY] ?? [];
        $options = array_merge($options, ['timeout' => $delay]);

        return $this->pushRaw(
            $this->makePayload($job, $data, $options),
            $queueName,
            $options
        );
    }

    /**
     * Recurring Jobs
     *
     * @param int $interval
     * @param string $job
     * @param array $data
     * @param string $queueName
     * @return string
     */
    public function recur(int $interval, string $job, array $data, ?string $queueName = null): string
    {
        /** @var \Qless\Queues\Queue $queue */
        $queue = $this->getConnection()->queues[$queueName];

        $options = $data[self::JOB_OPTIONS_KEY] ?? [];
        $options = array_merge($options, ['interval' => $interval]);

        return $queue->recur(
            $job,
            $data,
            $options['interval'],
            $options['offset'] ?? null,
            $options['jid'] ?? null,
            $options['retries'] ?? null,
            $options['priority'] ?? null,
            $options['backlog'] ?? null,
            $options['tags'] ?? null
        );
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string  $queueName
     * @return QlessJob|null
     */
    public function pop($queueName = null)
    {
        /** @var \Qless\Queues\Queue $queue */
        $queue = $this->getConnection()->queues[$queueName];

        /** @var BaseJob $job */
        $job = $queue->pop(self::WORKER_PREFIX . $this->connect->getWorkerName());

        if (!$job) {
            return null;
        }

        $payload = $this->makePayload($job->getKlass(), $job->getData());

        return new QlessJob(
            $this->container,
            $this,
            app()->make(JobHandler::class),
            $job,
            $payload
        );
    }

    /**
     * @param string $topic
     * @param string|null $queueName
     * @return bool
     */
    public function subscribe(string $topic, string $queueName = null): bool
    {
        $queueName = $queueName ?? $this->defaultQueue;

        /** @var \Qless\Queues\Queue $queue */
        $queue = $this->getConnection()->queues[$queueName];

        return $queue->subscribe($topic);
    }

    /**
     * @param string $topic
     * @param string|null $queueName
     * @return bool
     */
    public function unSubscribe(string $topic, string $queueName = null): bool
    {
        $queueName = $queueName ?? $this->defaultQueue;

        /** @var \Qless\Queues\Queue $queue */
        $queue = $this->getConnection()->queues[$queueName];

        return $queue->unSubscribe($topic);
    }

    /**
     * @param string $topicName
     * @param string $job
     * @param array $data
     * @param array $options
     * @return array|string
     */
    public function pushToTopic(string $topicName, string $job, array $data = [], array $options = [])
    {
        $topic = new Topic($topicName, $this->getConnection());

        $qlessOptions = $payloadData['data'][self::JOB_OPTIONS_KEY] ?? [];
        $options = array_merge($qlessOptions, $options);

        return $topic->put(
            $job,
            $data,
            $options['jid'] ?? null,
            $options['delay'] ?? null,
            $options['retries'] ?? null,
            $options['priority'] ?? null,
            $options['tags'] ?? null,
            $options['depends'] ?? null
        );
    }

    /**
     * @param string|object $job
     * @param mixed|string $data
     * @param array $options
     * @return string
     */
    protected function makePayload($job, $data = [], $options = []): string
    {
        $displayName = '';
        if ($job instanceof AbstractJob) {
            $displayName = get_class($job);
            $data = array_merge($job->toArray(), $data);
        } elseif (is_object($job)) {
            $displayName = get_class($job);
        }

        if (is_string($job)) {
            $displayName = explode('@', $job)[0];
        }

        $qlessOptions = $data[self::JOB_OPTIONS_KEY] ?? [];
        $data[self::JOB_OPTIONS_KEY] = array_merge($qlessOptions, $options);

        $payload = json_encode([
            'displayName' => $displayName,
            'job' => is_string($job) ? $job : $displayName,
            'data' => $data,
        ]);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: ' . json_last_error()
            );
        }

        return $payload;
    }

    /**
     * @return Client
     */
    public function getConnection(): Client
    {
        return $this->connect;
    }
}
