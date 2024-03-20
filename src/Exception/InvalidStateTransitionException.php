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

namespace JMS\JobQueueBundle\Exception;

use JMS\JobQueueBundle\Entity\Job;

class InvalidStateTransitionException extends \InvalidArgumentException
{
    public function __construct(private Job $job, private $newState, private array $allowedStates = [])
    {
        $msg = sprintf('The Job(id = %d) cannot change from "%s" to "%s". Allowed transitions: ', $job->getId(), $job->getState(), $newState);
        $msg .= count($allowedStates) > 0 ? '"'.implode('", "', $allowedStates).'"' : '#none#';
        parent::__construct($msg);
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getNewState()
    {
        return $this->newState;
    }

    public function getAllowedStates(): array
    {
        return $this->allowedStates;
    }
}