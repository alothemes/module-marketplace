<?php

namespace Swissup\Marketplace\Service;

use Magento\Framework\Stdlib\DateTime;
use Swissup\Marketplace\Model\Handler\Wrapper;
use Swissup\Marketplace\Model\Job;
use Swissup\Marketplace\Model\ResourceModel\Job\Collection;

class QueueDispatcher
{
    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $jsonSerializer;

    /**
     * @var \Swissup\Marketplace\Model\HandlerFactory
     */
    private $handlerFactory;

    /**
     * @var \Swissup\Marketplace\Model\JobFactory
     */
    private $jobFactory;

    /**
     * @param \Magento\Framework\Serialize\Serializer\Json $jsonSerializer
     * @param \Swissup\Marketplace\Model\HandlerFactory $handlerFactory
     * @param \Swissup\Marketplace\Model\JobFactory $jobFactory
     */
    public function __construct(
        \Magento\Framework\Serialize\Serializer\Json $jsonSerializer,
        \Swissup\Marketplace\Model\HandlerFactory $handlerFactory,
        \Swissup\Marketplace\Model\JobFactory $jobFactory
    ) {
        $this->jsonSerializer = $jsonSerializer;
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
                if ($job->getHandler()) {
                    $output = $job->getHandler()->execute();
                }

                $job->setStatus(Job::STATUS_SUCCESS)
                    ->setOutput((string) $output);
            } catch (\Exception $e) {
                $job->setStatus(Job::STATUS_ERRORED)
                    ->setOutput($e->getMessage());
            } finally {
                $job->setFinishedAt($this->getCurrentDate())
                    ->save();
            }
        }
    }

    /**
     * @param Collection $collection
     * @return array
     * @throws \Exception
     */
    private function prepareQueue(Collection $collection)
    {
        $queue = $collection->getItems();
        foreach ($queue as $job) {
            $job->reset()->setStatus(Job::STATUS_QUEUED)->save();
        }

        $beforeQueue = [];
        $afterQueue = [];
        foreach ($queue as $job) {
            try {
                $handler = $this->createHandler($job);
            } catch (\Exception $e) {
                continue;
            }

            $beforeQueue += $handler->beforeQueue();
            $afterQueue += $handler->afterQueue();

            $job->setHandler($handler);
        }

        if ($beforeQueue) {
            $createdAt = $collection->getFirstItem()->getCreatedAt();
            $createdAt = (new \DateTime($createdAt))->modify('-1 second');
            $createdAt = $createdAt->format(DateTime::DATETIME_PHP_FORMAT);

            $preProcess = $this->createJob([
                    'class' => Wrapper::class,
                    'created_at' => $createdAt,
                    'arguments_serialized' => $this->jsonSerializer->serialize([
                        'tasks' => $beforeQueue,
                    ]),
                ])
                ->save();

            array_unshift($queue, $preProcess);
        }

        if ($afterQueue) {
            $postProcess = $this->createJob([
                    'class' => Wrapper::class,
                    'arguments_serialized' => $this->jsonSerializer->serialize([
                        'tasks' => $afterQueue,
                    ]),
                ])
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
            return $this->handlerFactory->create($job);
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
