<?php

namespace Swissup\Marketplace\Service;

use Magento\Framework\Stdlib\DateTime;
use Swissup\Marketplace\Model\Handler\Additional\Wrapper;
use Swissup\Marketplace\Model\Job;
use Swissup\Marketplace\Model\ResourceModel\Job\Collection;

class QueueDispatcher
{
    /**
     * @var \Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory $cronCollectionFactory
     */
    private $cronCollectionFactory;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $jsonSerializer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Swissup\Marketplace\Model\HandlerFactory
     */
    private $handlerFactory;

    /**
     * @var \Swissup\Marketplace\Model\JobFactory
     */
    private $jobFactory;

    /**
     * @param \Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory $cronCollectionFactory
     * @param \Magento\Framework\Serialize\Serializer\Json $jsonSerializer
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Swissup\Marketplace\Model\HandlerFactory $handlerFactory
     * @param \Swissup\Marketplace\Model\JobFactory $jobFactory
     */
    public function __construct(
        \Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory $cronCollectionFactory,
        \Magento\Framework\Serialize\Serializer\Json $jsonSerializer,
        \Psr\Log\LoggerInterface $logger,
        \Swissup\Marketplace\Model\HandlerFactory $handlerFactory,
        \Swissup\Marketplace\Model\JobFactory $jobFactory
    ) {
        $this->cronCollectionFactory = $cronCollectionFactory;
        $this->jsonSerializer = $jsonSerializer;
        $this->logger = $logger;
        $this->handlerFactory = $handlerFactory;
        $this->jobFactory = $jobFactory;
    }

    /**
     * @param Collection $collection
     * @return void
     */
    public function dispatch(Collection $collection)
    {
        if (!$collection->count()) {
            return;
        }

        try {
            $queue = $this->prepareQueue($collection);
        } catch (\Exception $e) {
            // cancel all jobs if some are not prepared
            foreach ($collection as $job) {
                if ($job->getStatus() !== JOB::STATUS_QUEUED) {
                    continue;
                }

                $job->setStatus(Job::STATUS_CANCELED)
                    ->setFinishedAt($this->getCurrentDate())
                    ->save();
            }

            return;
        }

        foreach ($queue as $job) {
            if ($job->getStatus() !== JOB::STATUS_QUEUED) {
                // some tasks may be declined during preparation
                continue;
            }

            try {
                $job->setStatus(Job::STATUS_RUNNING)
                    ->setStartedAt($this->getCurrentDate())
                    ->setAttempts($job->getAttempts() + 1)
                    ->save();

                $output = '';
                if ($handler = $job->getHandler()) {
                    $output = $handler->handle();
                }

                $job->setStatus(Job::STATUS_SUCCESS)
                    ->setOutput((string) $output);
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                $job->setStatus(Job::STATUS_ERRORED)
                    ->setOutput($e->getMessage());
            } finally {
                $job->setFinishedAt($this->getCurrentDate())
                    ->save();
            }
        }

        $this->logger->info("Done\n");
    }

    /**
     * @param Collection $collection
     * @return array
     * @throws \Exception
     */
    private function prepareQueue(Collection $collection)
    {
        $cron = $this->cronCollectionFactory->create()
            ->addFieldToFilter('job_code', 'swissup_marketplace_job_run')
            ->addFieldToFilter('status', \Magento\Cron\Model\Schedule::STATUS_RUNNING)
            ->getFirstItem();

        $queue = $collection->getItems();
        foreach ($queue as $job) {
            $job->reset()
                ->setStatus(Job::STATUS_QUEUED)
                ->setCronScheduleId($cron->getId())
                ->save();
        }

        $beforeQueue = [];
        $afterQueue = [];
        $ip = [];
        foreach ($queue as $job) {
            try {
                $handler = $this->createHandler($job);
            } catch (\Exception $e) {
                continue;
            }

            $beforeQueue += $handler->beforeQueue();
            $afterQueue += $handler->afterQueue();
            $ip[] = $handler->getIp();

            $job->setHandler($handler);
        }

        $beforeQueue = array_keys(array_filter($beforeQueue));
        $afterQueue = array_keys(array_filter($afterQueue));
        $ip = implode(',', array_filter(array_unique($ip)));

        if ($beforeQueue) {
            $createdAt = $collection->getFirstItem()->getCreatedAt();
            $createdAt = (new \DateTime($createdAt))->modify('-1 second');
            $createdAt = $createdAt->format(DateTime::DATETIME_PHP_FORMAT);

            $preProcess = $this->createJob([
                    'class' => Wrapper::class,
                    'created_at' => $createdAt,
                    'arguments_serialized' => $this->jsonSerializer->serialize([
                        'tasks' => $beforeQueue,
                        'data' => [
                            'ip' => $ip,
                        ],
                    ]),
                ])
                ->setCronScheduleId($cron->getId())
                ->save();

            array_unshift($queue, $preProcess);
        }

        if ($afterQueue) {
            $postProcess = $this->createJob([
                    'class' => Wrapper::class,
                    'arguments_serialized' => $this->jsonSerializer->serialize([
                        'tasks' => $afterQueue,
                        'data' => [
                            'ip' => $ip,
                        ],
                    ]),
                ])
                ->setCronScheduleId($cron->getId())
                ->save();

            array_push($queue, $postProcess);
        }

        return $queue;
    }

    private function createJob($data)
    {
        $defaults = [
            'arguments_serialized' => '{}',
            'created_at' => $this->getCurrentDate(),
            'status' => Job::STATUS_QUEUED,
        ];

        if (is_string($data)) {
            $data = ['class' => $data];
        }

        $job = $this->jobFactory->create()
            ->addData(array_merge($defaults, $data));

        $job->setHandler($this->createHandler($job));

        return $job;
    }

    /**
     * @param Job $job
     * @return HandlerInterface
     */
    private function createHandler(Job $job)
    {
        try {
            return $this->handlerFactory->create($job)->setLogger($this->logger);
        } catch (\Exception $e) {
            $job->setStatus(JOB::STATUS_ERRORED)
                ->setOutput($e->getMessage())
                ->setFinishedAt($this->getCurrentDate())
                ->save();
            throw $e;
        }
    }

    /**
     * @return string
     */
    private function getCurrentDate()
    {
        return (new \DateTime())->format(DateTime::DATETIME_PHP_FORMAT);
    }
}
