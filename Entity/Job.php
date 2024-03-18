<?php

namespace Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Entity\Listener\ManyToAnyListener;
use Exception\InvalidStateTransitionException;
use Exception\LogicException;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

#[Entity]
#[Table(name: "jms_jobs")]
#[Index(name: "cmd_search_index", columns: ["command"])]
#[Index(name: "sorting_index", columns: ["state", "priority", "id"])]
#[ChangeTrackingPolicy("DEFERRED_EXPLICIT")]
#[EntityListeners([ManyToAnyListener::class])]
class Job
{
    /** State if job is inserted, but not yet ready to be started. */
    const STATE_NEW = 'new';

    /**
     * State if job is inserted, and might be started.
     *
     * It is important to note that this does not automatically mean that all
     * jobs of this state can actually be started, but you have to check
     * isStartable() to be absolutely sure.
     *
     * In contrast to NEW, jobs of this state at least might be started,
     * while jobs of state NEW never are allowed to be started.
     */
    const STATE_PENDING = 'pending';

    /** State if job was never started, and will never be started. */
    const STATE_CANCELED = 'canceled';

    /** State if job was started and has not exited, yet. */
    const STATE_RUNNING = 'running';

    /** State if job exists with a successful exit code. */
    const STATE_FINISHED = 'finished';

    /** State if job exits with a non-successful exit code. */
    const STATE_FAILED = 'failed';

    /** State if job exceeds its configured maximum runtime. */
    const STATE_TERMINATED = 'terminated';

    /**
     * State if an error occurs in the runner command.
     *
     * The runner command is the command that actually launches the individual
     * jobs. If instead an error occurs in the job command, this will result
     * in a state of FAILED.
     */
    const STATE_INCOMPLETE = 'incomplete';

    /**
     * State if an error occurs in the runner command.
     *
     * The runner command is the command that actually launches the individual
     * jobs. If instead an error occurs in the job command, this will result
     * in a state of FAILED.
     */
    const DEFAULT_QUEUE = 'default';
    const MAX_QUEUE_LENGTH = 50;

    const PRIORITY_LOW = -5;
    const PRIORITY_DEFAULT = 0;
    const PRIORITY_HIGH = 5;

    #[Id]
    #[GeneratedValue(strategy: "AUTO")]
    #[Column(type: "bigint", options: ["unsigned" => true])]
    private int $id;

    #[Column(type: "string", length: 15)]
    private string $state;

    #[Column(type: "string", length: Job::MAX_QUEUE_LENGTH)]
    private string $queue;

    #[Column(type: "smallint")]
    private int $priority = 0;

    #[Column(name: "createdAt")]
    private DateTime $createdAt;

    #[Column(name: "startedAt", nullable: true)]
    private DateTime|null $startedAt;

    #[Column(name: "checkedAt", nullable: true)]
    private DateTime|null $checkedAt;

    #[Column(name: "workerName", length: 50, nullable: true)]
    private string|null $workerName;

    #[Column(name: "executeAfter", nullable: true)]
    private DateTime|null $executeAfter;

    #[Column(name: "closedAt", nullable: true)]
    private DateTime|null $closedAt;

    #[Column]
    private string $command;

    #[Column(type: "json")]
    private array $args;

    #[ManyToMany(targetEntity: Job::class, fetch: "EAGER")]
    #[JoinTable(name: "jms_job_dependencies")]
    #[JoinColumn(name: 'source_job_id', referencedColumnName: 'id')]
    #[InverseJoinColumn(name: 'dest_job_id', referencedColumnName: 'id')]
    private ArrayCollection $dependencies;

    #[Column(type: "text", nullable: true)]
    private string $output;

    #[Column(name: "errorOutput", type: "text", nullable: true)]
    private string $errorOutput;

    #[Column(name: "exitCode", type: "smallint", nullable: true, options: ["unsigned" => true])]
    private int $exitCode;

    #[Column(name: "maxRuntime", type: "smallint", nullable: true, options: ["unsigned" => true])]
    private int $maxRuntime = 0;

    #[Column(name: "maxRetries", type: "smallint", nullable: true, options: ["unsigned" => true])]
    private int $maxRetries = 0;

    #[ManyToOne(targetEntity: Job::class, inversedBy: "retryJobs")]
    #[JoinColumn(name: "originalJob_id", referencedColumnName: "id")]
    private Job $originalJob;

    #[OneToMany(targetEntity: Job::class, mappedBy: "originalJob", cascade: ["persist", "remove", "detach", "refresh"])]
    private ArrayCollection $retryJobs;

    #[Column(name: "stackTrace", type: "jms_job_safe_object", nullable: true)]
    private $stackTrace;

    #[Column(type: "smallint", nullable: true, options: ["unsigned" => true])]
    private int|null $runtime;

    #[Column(name: "memoryUsage", type: "integer", nullable: true, options: ["unsigned" => true])]
    private int|null $memoryUsage;

    #[Column(name: "memoryUsageReal", type: "integer", nullable: true, options: ["unsigned" => true])]
    private int|null $memoryUsageReal;

    /**
     * This may store any entities which are related to this job, and are
     * managed by Doctrine.
     *
     * It is effectively a many-to-any association.
     */
    private ArrayCollection $relatedEntities;

    public function __construct($command, array $args = array(), $confirmed = true, $queue = self::DEFAULT_QUEUE, $priority = self::PRIORITY_DEFAULT)
    {
        if (trim($queue) === '') {
            throw new \InvalidArgumentException('$queue must not be empty.');
        }
        if (strlen($queue) > self::MAX_QUEUE_LENGTH) {
            throw new \InvalidArgumentException(sprintf('The maximum queue length is %d, but got "%s" (%d chars).', self::MAX_QUEUE_LENGTH, $queue, strlen($queue)));
        }

        $this->command = $command;
        $this->args = $args;
        $this->state = $confirmed ? self::STATE_PENDING : self::STATE_NEW;
        $this->queue = $queue;
        $this->priority = $priority * -1;
        $this->createdAt = new DateTime();
        $this->executeAfter = new DateTime();
        $this->executeAfter = $this->executeAfter->modify('-1 second');
        $this->dependencies = new ArrayCollection();
        $this->retryJobs = new ArrayCollection();
        $this->relatedEntities = new ArrayCollection();
    }

    public function __clone()
    {
        $this->state = self::STATE_PENDING;
        $this->createdAt = new DateTime();
        $this->startedAt = null;
        $this->checkedAt = null;
        $this->closedAt = null;
        $this->workerName = null;
        $this->output = null;
        $this->errorOutput = null;
        $this->exitCode = 0;
        $this->stackTrace = null;
        $this->runtime = 0;
        $this->memoryUsage = null;
        $this->memoryUsageReal = null;
        $this->relatedEntities = new ArrayCollection();
    }

    public static function create($command, array $args = array(), $confirmed = true, $queue = self::DEFAULT_QUEUE, $priority = self::PRIORITY_DEFAULT): Job
    {
        return new self($command, $args, $confirmed, $queue, $priority);
    }

    public static function isNonSuccessfulFinalState($state): bool
    {
        return in_array($state, array(self::STATE_CANCELED, self::STATE_FAILED, self::STATE_INCOMPLETE, self::STATE_TERMINATED), true);
    }

    public static function getStates(): array
    {
        return array(
            self::STATE_NEW,
            self::STATE_PENDING,
            self::STATE_CANCELED,
            self::STATE_RUNNING,
            self::STATE_FINISHED,
            self::STATE_FAILED,
            self::STATE_TERMINATED,
            self::STATE_INCOMPLETE
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setWorkerName($workerName): void
    {
        $this->workerName = $workerName;
    }

    public function getWorkerName(): string|null
    {
        return $this->workerName;
    }

    public function getPriority(): float|int
    {
        return $this->priority * -1;
    }

    public function isInFinalState(): bool
    {
        return ! $this->isNew() && ! $this->isPending() && ! $this->isRunning();
    }

    public function isStartable(): bool
    {
        foreach ($this->dependencies as $dep) {
            if ($dep->getState() !== self::STATE_FINISHED) {
                return false;
            }
        }

        return true;
    }

    public function setState($newState): void
    {
        if ($newState === $this->state) {
            return;
        }

        switch ($this->state) {
            case self::STATE_NEW:
                if ( ! in_array($newState, array(self::STATE_PENDING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_PENDING, self::STATE_CANCELED));
                }

                if (self::STATE_CANCELED === $newState) {
                    $this->closedAt = new DateTime();
                }

                break;

            case self::STATE_PENDING:
                if ( ! in_array($newState, array(self::STATE_RUNNING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_RUNNING, self::STATE_CANCELED));
                }

                if ($newState === self::STATE_RUNNING) {
                    $this->startedAt = new DateTime();
                    $this->checkedAt = new DateTime();
                } else if ($newState === self::STATE_CANCELED) {
                    $this->closedAt = new DateTime();
                }

                break;

            case self::STATE_RUNNING:
                if ( ! in_array($newState, array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED, self::STATE_INCOMPLETE))) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED, self::STATE_INCOMPLETE));
                }

                $this->closedAt = new DateTime();

                break;

            case self::STATE_FINISHED:
            case self::STATE_FAILED:
            case self::STATE_TERMINATED:
            case self::STATE_INCOMPLETE:
                throw new InvalidStateTransitionException($this, $newState);

            default:
                throw new LogicException('The previous cases were exhaustive. Unknown state: '.$this->state);
        }

        $this->state = $newState;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getClosedAt(): DateTime|null
    {
        return $this->closedAt;
    }

    public function getExecuteAfter(): DateTime|bool|null
    {
        return $this->executeAfter;
    }

    public function setExecuteAfter(DateTime $executeAfter): void
    {
        $this->executeAfter = $executeAfter;
    }


    public function getCommand(): string
    {
        return $this->command;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getRelatedEntities(): ArrayCollection
    {
        return $this->relatedEntities;
    }

    public function isClosedNonSuccessful(): bool
    {
        return self::isNonSuccessfulFinalState($this->state);
    }

    public function findRelatedEntity($class)
    {
        foreach ($this->relatedEntities as $entity) {
            if ($entity instanceof $class) {
                return $entity;
            }
        }

        return null;
    }

    public function addRelatedEntity($entity): void
    {
        if ( ! is_object($entity)) {
            throw new \RuntimeException(sprintf('$entity must be an object.'));
        }

        if ($this->relatedEntities->contains($entity)) {
            return;
        }

        $this->relatedEntities->add($entity);
    }

    public function getDependencies(): ArrayCollection
    {
        return $this->dependencies;
    }

    public function hasDependency(Job $job): bool
    {
        return $this->dependencies->contains($job);
    }

    public function addDependency(Job $job): void
    {
        if ($this->dependencies->contains($job)) {
            return;
        }

        if ($this->mightHaveStarted()) {
            throw new \LogicException('You cannot add dependencies to a job which might have been started already.');
        }

        $this->dependencies->add($job);
    }

    public function getRuntime(): int|null
    {
        return $this->runtime;
    }

    public function setRuntime($time): void
    {
        $this->runtime = (integer) $time;
    }

    public function getMemoryUsage(): int|null
    {
        return $this->memoryUsage;
    }

    public function getMemoryUsageReal(): int|null
    {
        return $this->memoryUsageReal;
    }

    public function addOutput($output): void
    {
        $this->output .= $output;
    }

    public function addErrorOutput($output): void
    {
        $this->errorOutput .= $output;
    }

    public function setOutput($output): void
    {
        $this->output = $output;
    }

    public function setErrorOutput($output): void
    {
        $this->errorOutput = $output;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    public function setExitCode($code): void
    {
        $this->exitCode = $code;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function setMaxRuntime($time): void
    {
        $this->maxRuntime = (integer) $time;
    }

    public function getMaxRuntime(): int
    {
        return $this->maxRuntime;
    }

    public function getStartedAt(): DateTime|null
    {
        return $this->startedAt;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setMaxRetries($tries): void
    {
        $this->maxRetries = (integer) $tries;
    }

    public function isRetryAllowed(): bool
    {
        // If no retries are allowed, we can bail out directly, and we
        // do not need to initialize the retryJobs relation.
        if (0 === $this->maxRetries) {
            return false;
        }

        return count($this->retryJobs) < $this->maxRetries;
    }

    public function getOriginalJob(): Job
    {
        if (null === $this->originalJob) {
            return $this;
        }

        return $this->originalJob;
    }

    public function setOriginalJob(Job $job): void
    {
        if (self::STATE_PENDING !== $this->state) {
            throw new \LogicException($this.' must be in state "PENDING".');
        }

        if (null !== $this->originalJob) {
            throw new \LogicException($this.' already has an original job set.');
        }

        $this->originalJob = $job;
    }

    public function addRetryJob(Job $job): void
    {
        if (self::STATE_RUNNING !== $this->state) {
            throw new \LogicException('Retry jobs can only be added to running jobs.');
        }

        $job->setOriginalJob($this);
        $this->retryJobs->add($job);
    }

    public function getRetryJobs(): ArrayCollection
    {
        return $this->retryJobs;
    }

    public function isRetryJob(): bool
    {
        return null !== $this->originalJob;
    }

    public function isRetried(): bool
    {
        foreach ($this->retryJobs as $job) {
            /** @var Job $job */

            if ( ! $job->isInFinalState()) {
                return true;
            }
        }

        return false;
    }

    public function checked(): void
    {
        $this->checkedAt = new DateTime();
    }

    public function getCheckedAt(): DateTime|null
    {
        return $this->checkedAt;
    }

    public function setStackTrace(FlattenException $ex): void
    {
        $this->stackTrace = $ex;
    }

    public function getStackTrace()
    {
        return $this->stackTrace;
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function isNew(): bool
    {
        return self::STATE_NEW === $this->state;
    }

    public function isPending(): bool
    {
        return self::STATE_PENDING === $this->state;
    }

    public function isCanceled(): bool
    {
        return self::STATE_CANCELED === $this->state;
    }

    public function isRunning(): bool
    {
        return self::STATE_RUNNING === $this->state;
    }

    public function isTerminated(): bool
    {
        return self::STATE_TERMINATED === $this->state;
    }

    public function isFailed(): bool
    {
        return self::STATE_FAILED === $this->state;
    }

    public function isFinished(): bool
    {
        return self::STATE_FINISHED === $this->state;
    }

    public function isIncomplete(): bool
    {
        return self::STATE_INCOMPLETE === $this->state;
    }

    public function __toString()
    {
        return sprintf('Job(id = %s, command = "%s")', $this->id, $this->command);
    }

    private function mightHaveStarted(): bool
    {
        if (null === $this->id) {
            return false;
        }

        if (self::STATE_NEW === $this->state) {
            return false;
        }

        if (self::STATE_PENDING === $this->state && ! $this->isStartable()) {
            return false;
        }

        return true;
    }
}
