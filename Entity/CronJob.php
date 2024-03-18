<?php

namespace Entity;

use DateTime;
use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: "jms_cron_jobs")]
#[ChangeTrackingPolicy("DEFERRED_EXPLICIT")]
class CronJob
{
    #[Id]
    #[Column(type: "integer", options: ["unsigned" => true])]
    #[GeneratedValue(strategy: "AUTO")]
    private int $id;

    #[Column(type: "string", length: 200, unique: true)]
    private string $command;

    #[Column(name: "lastRunAt", type: "datetime")]
    private DateTime $lastRunAt;

    public function __construct($command)
    {
        $this->command = $command;
        $this->lastRunAt = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getLastRunAt(): DateTime
    {
        return $this->lastRunAt;
    }
}