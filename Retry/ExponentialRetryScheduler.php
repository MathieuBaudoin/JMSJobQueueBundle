<?php

namespace Retry;

use DateTime;
use Entity\Job;

class ExponentialRetryScheduler implements RetryScheduler
{
    private mixed $base;

    public function __construct($base = 5)
    {
        $this->base = $base;
    }

    public function scheduleNextRetry(Job $originalJob): DateTime
    {
        return new DateTime('+'.(pow($this->base, count($originalJob->getRetryJobs()))).' seconds');
    }
}