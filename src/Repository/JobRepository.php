<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\JobQueueBundle\Repository;

use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use JMS\JobQueueBundle\Retry\ExponentialRetryScheduler;
use JMS\JobQueueBundle\Retry\RetryScheduler;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JobRepository extends ServiceEntityRepository
{
    public function __construct(private readonly ManagerRegistry $managerRegistry,
                                private readonly EventDispatcherInterface $eventDispatcher,
                                private RetryScheduler $retryScheduler) {
        parent::__construct($this->managerRegistry, Job::class);
    }

    /**
     * @param $command
     * @param array $args
     * @return mixed
     */
    public function findJob($command, array $args = []): mixed
    {
        return $this->getEntityManager()->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args")
            ->setParameter('command', $command)
            ->setParameter('args', $args, 'json')
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * @param $command
     * @param array $args
     * @return mixed
     */
    public function getJob($command, array $args = []): mixed
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        throw new RuntimeException(sprintf('Found no job for command "%s" with args "%s".', $command, json_encode($args)));
    }

    /**
     * @param $command
     * @param array $args
     * @return Job|mixed
     * @throws ORMException
     */
    public function getOrCreateIfNotExists($command, array $args = []): mixed
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        $job = new Job($command, $args, false);
        $this->getEntityManager()->persist($job);
        $this->getEntityManager()->flush();

        $firstJob = $this->getEntityManager()->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args ORDER BY j.id ASC")
             ->setParameter('command', $command)
             ->setParameter('args', $args, 'json_array')
             ->setMaxResults(1)
             ->getSingleResult();

        if ($firstJob === $job) {
            $job->setState(Job::STATE_PENDING);
            $this->getEntityManager()->persist($job);
            $this->getEntityManager()->flush();

            return $job;
        }

        $this->getEntityManager()->remove($job);
        $this->getEntityManager()->flush();

        return $firstJob;
    }

    /**
     * @param $workerName
     * @param array $excludedIds
     * @param array $excludedQueues
     * @param array $restrictedQueues
     * @return mixed|null
     * @throws Exception
     */
    public function findStartableJob($workerName, array &$excludedIds = [], array $excludedQueues = [], array $restrictedQueues = []): mixed
    {
        while (null !== $job = $this->findPendingJob($excludedIds, $excludedQueues, $restrictedQueues)) {
            if ($job->isStartable() && $this->acquireLock($workerName, $job)) {
                return $job;
            }

            $excludedIds[] = $job->getId();

            // We do not want to have non-startable jobs floating around in
            // cache as they might be changed by another process. So, better
            // re-fetch them when they are not excluded anymore.
            $this->getEntityManager()->detach($job);
        }

        return null;
    }

    /**
     * @param string $workerName
     * @param Job $job
     * @return bool
     * @throws Exception
     */
    private function acquireLock(string$workerName, Job $job): bool
    {
        $affectedRows = $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE jms_jobs SET workerName = :worker WHERE id = :id AND workerName IS NULL",
            [
                'worker' => $workerName,
                'id' => $job->getId()
            ]
        );

        if ($affectedRows > 0) {
            $job->setWorkerName($workerName);

            return true;
        }

        return false;
    }

    /**
     * @param $relatedEntity
     * @return mixed
     */
    public function findAllForRelatedEntity($relatedEntity): mixed
    {
        list($relClass, $relId) = $this->getRelatedEntityIdentifier($relatedEntity);

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Job::class, 'j');

        return $this->getEntityManager()->createNativeQuery("SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId", $rsm)
                    ->setParameter('relClass', $relClass)
                    ->setParameter('relId', $relId)
                    ->getResult();
    }

    /**
     * @param $command
     * @param $relatedEntity
     * @return mixed
     */
    public function findOpenJobForRelatedEntity($command, $relatedEntity): mixed
    {
        return $this->findJobForRelatedEntity($command, $relatedEntity, array(Job::STATE_RUNNING, Job::STATE_PENDING, Job::STATE_NEW));
    }

    /**
     * @param $command
     * @param $relatedEntity
     * @param array $states
     * @return mixed
     */
    public function findJobForRelatedEntity($command, $relatedEntity, array $states = []): mixed
    {
        list($relClass, $relId) = $this->getRelatedEntityIdentifier($relatedEntity);

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Job::class, 'j');

        $sql = "SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId AND j.command = :command";
        $params = new ArrayCollection();
        $params->add(new Parameter('command', $command));
        $params->add(new Parameter('relClass', $relClass));
        $params->add(new Parameter('relId', $relId));

        if ( ! empty($states)) {
            $sql .= " AND j.state IN (:states)";
            $params->add(new Parameter('states', $states, ArrayParameterType::STRING));
        }

        return $this->getEntityManager()->createNativeQuery($sql, $rsm)
                   ->setParameters($params)
                   ->getOneOrNullResult();
    }

    /**
     * @param $entity
     * @return array
     */
    private function getRelatedEntityIdentifier($entity): array
    {
        if (!is_object($entity)) {
            throw new RuntimeException('$entity must be an object.');
        }

        if ($entity instanceof Proxy) {
            $entity->__load();
        }

        $relClass = ClassUtils::getClass($entity);
        $relId = $this->managerRegistry->getManagerForClass($relClass)->getMetadataFactory()
                    ->getMetadataFor($relClass)->getIdentifierValues($entity);
        asort($relId);

        if (!$relId) {
            throw new InvalidArgumentException(sprintf('The identifier for entity of class "%s" was empty.', $relClass));
        }

        return array($relClass, json_encode($relId));
    }

    /**
     * @param array $excludedIds
     * @param array $excludedQueues
     * @param array $restrictedQueues
     * @return mixed
     */
    public function findPendingJob(array $excludedIds = [], array $excludedQueues = [], array $restrictedQueues = []): mixed
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('j')->from('JMSJobQueueBundle:Job', 'j')
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.id', 'ASC');

        $conditions = [];

        $conditions[] = $qb->expr()->isNull('j.workerName');

        $conditions[] = $qb->expr()->lt('j.executeAfter', ':now');
        $qb->setParameter(':now', new DateTime(), 'datetime');

        $conditions[] = $qb->expr()->eq('j.state', ':state');
        $qb->setParameter('state', Job::STATE_PENDING);

        if (!empty($excludedIds)) {
            $conditions[] = $qb->expr()->notIn('j.id', ':excludedIds');
            $qb->setParameter('excludedIds', $excludedIds, ArrayParameterType::STRING);
        }

        if (!empty($excludedQueues)) {
            $conditions[] = $qb->expr()->notIn('j.queue', ':excludedQueues');
            $qb->setParameter('excludedQueues', $excludedQueues, ArrayParameterType::STRING);
        }

        if (!empty($restrictedQueues)) {
            $conditions[] = $qb->expr()->in('j.queue', ':restrictedQueues');
            $qb->setParameter('restrictedQueues', $restrictedQueues, ArrayParameterType::STRING);
        }

        $qb->where(call_user_func_array(array($qb->expr(), 'andX'), $conditions));

        return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }

    /**
     * @param Job $job
     * @param $finalState
     * @return void
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Exception
     */
    public function closeJob(Job $job, $finalState): void
    {
        $this->getEntityManager()->getConnection()->beginTransaction();
        try {
            $visited = array();
            $this->closeJobInternal($job, $finalState, $visited);
            $this->getEntityManager()->flush();
            $this->getEntityManager()->getConnection()->commit();

            // Clean-up entity manager to allow for garbage collection to kick in.
            foreach ($visited as $job) {
                // If the job is an original job which is now being retried, let's
                // not remove it just yet.
                if ( ! $job->isClosedNonSuccessful() || $job->isRetryJob()) {
                    continue;
                }

                $this->getEntityManager()->detach($job);
            }
        } catch (\Exception $ex) {
            $this->getEntityManager()->getConnection()->rollback();

            throw $ex;
        }
    }

    /**
     * @param Job $job
     * @param $finalState
     * @param array $visited
     * @return void
     * @throws Exception
     * @throws \Exception
     */
    private function closeJobInternal(Job $job, $finalState, array &$visited = []): void
    {
        if (in_array($job, $visited, true)) {
            return;
        }
        $visited[] = $job;

        if ($job->isInFinalState()) {
            return;
        }

        if (null !== $this->eventDispatcher && ($job->isRetryJob() || 0 === count($job->getRetryJobs()))) {
            $event = new StateChangeEvent($job, $finalState);
            $this->eventDispatcher->dispatch($event, 'jms_job_queue.job_state_change');
            $finalState = $event->getNewState();
        }

        switch ($finalState) {
            case Job::STATE_CANCELED:
                $job->setState(Job::STATE_CANCELED);
                $this->getEntityManager()->persist($job);

                if ($job->isRetryJob()) {
                    $this->closeJobInternal($job->getOriginalJob(), Job::STATE_CANCELED, $visited);

                    return;
                }

                foreach ($this->findIncomingDependencies($job) as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FAILED:
            case Job::STATE_TERMINATED:
            case Job::STATE_INCOMPLETE:
                if ($job->isRetryJob()) {
                    $job->setState($finalState);
                    $this->getEntityManager()->persist($job);

                    $this->closeJobInternal($job->getOriginalJob(), $finalState);

                    return;
                }

                // The original job has failed, and we are allowed to retry it.
                if ($job->isRetryAllowed()) {
                    $retryJob = new Job($job->getCommand(), $job->getArgs(), true, $job->getQueue(), $job->getPriority());
                    $retryJob->setMaxRuntime($job->getMaxRuntime());

                    if ($this->retryScheduler === null) {
                        $this->retryScheduler = new ExponentialRetryScheduler(5);
                    }

                    $retryJob->setExecuteAfter($this->retryScheduler->scheduleNextRetry($job));

                    $job->addRetryJob($retryJob);
                    $this->getEntityManager()->persist($retryJob);
                    $this->getEntityManager()->persist($job);

                    return;
                }

                $job->setState($finalState);
                $this->getEntityManager()->persist($job);

                // The original job has failed, and no retries are allowed.
                foreach ($this->findIncomingDependencies($job) as $dep) {
                    // This is a safe-guard to avoid blowing up if there is a database inconsistency.
                    if ( ! $dep->isPending() && ! $dep->isNew()) {
                        continue;
                    }

                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FINISHED:
                if ($job->isRetryJob()) {
                    $job->getOriginalJob()->setState($finalState);
                    $this->getEntityManager()->persist($job->getOriginalJob());
                }
                $job->setState($finalState);
                $this->getEntityManager()->persist($job);

                return;

            default:
                throw new LogicException(sprintf('Non allowed state "%s" in closeJobInternal().', $finalState));
        }
    }

    /**
     * @return Job[]
     * @throws Exception
     */
    public function findIncomingDependencies(Job $job): array
    {
        $jobIds = $this->getJobIdsOfIncomingDependencies($job);
        if (empty($jobIds)) {
            return array();
        }

        return $this->getEntityManager()->createQuery("SELECT j, d FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE j.id IN (:ids)")
                    ->setParameter('ids', $jobIds)
                    ->getResult();
    }

    /**
     * @return Job[]
     * @throws Exception
     */
    public function getIncomingDependencies(Job $job): array
    {
        $jobIds = $this->getJobIdsOfIncomingDependencies($job);
        if (empty($jobIds)) {
            return array();
        }

        return $this->getEntityManager()->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.id IN (:ids)")
                    ->setParameter('ids', $jobIds)
                    ->getResult();
    }

    /**
     * @param Job $job
     * @return mixed
     * @throws Exception
     */
    private function getJobIdsOfIncomingDependencies(Job $job): mixed
    {
        return $this->getEntityManager()->getConnection()
            ->executeQuery("SELECT source_job_id FROM jms_job_dependencies WHERE dest_job_id = :id", ['id' => $job->getId()])
            ->fetchFirstColumn();
    }

    /**
     * @param int $nbJobs
     * @return mixed
     */
    public function findLastJobsWithError(int $nbJobs = 10): mixed
    {
        return $this->getEntityManager()->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.state IN (:errorStates) AND j.originalJob IS NULL ORDER BY j.closedAt DESC")
                    ->setParameter('errorStates', array(Job::STATE_TERMINATED, Job::STATE_FAILED))
                    ->setMaxResults($nbJobs)
                    ->getResult();
    }

    /**
     * @return array
     */
    public function getAvailableQueueList(): array
    {
        $queues =  $this->getEntityManager()->createQuery("SELECT DISTINCT j.queue FROM JMSJobQueueBundle:Job j WHERE j.state IN (:availableStates)  GROUP BY j.queue")
            ->setParameter('availableStates', array(Job::STATE_RUNNING, Job::STATE_NEW, Job::STATE_PENDING))
            ->getResult();

        $newQueueArray = array();

        foreach($queues as $queue) {
            $newQueue = $queue['queue'];
            $newQueueArray[] = $newQueue;
        }

        return $newQueueArray;
    }

    /**
     * @param $jobQueue
     * @return int
     */
    public function getAvailableJobsForQueueCount($jobQueue): int
    {
        $result = $this->getEntityManager()->createQuery("SELECT j.queue FROM JMSJobQueueBundle:Job j WHERE j.state IN (:availableStates) AND j.queue = :queue")
            ->setParameter('availableStates', array(Job::STATE_RUNNING, Job::STATE_NEW, Job::STATE_PENDING))
            ->setParameter('queue', $jobQueue)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return count($result);
    }
}
