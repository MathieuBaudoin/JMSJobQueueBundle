<?php

declare(strict_types = 1);

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Console\ScheduleInSecondInterval;

trait ScheduleHourly
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 3600;
    }
}