<?php

declare(strict_types = 1);

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Console\ScheduleInSecondInterval;

trait ScheduleDaily
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 86400;
    }
}