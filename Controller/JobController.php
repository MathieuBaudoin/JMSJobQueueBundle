<?php

namespace JMS\JobQueueBundle\Controller;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Entity\Job;
use Entity\Repository\JobManager;
use View\JobFilter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class JobController
{
    public function __construct(private readonly JobManager $jobManager, private readonly ManagerRegistry $managerRegistry,
                                private readonly Environment $twig, private readonly RouterInterface $router,
                                private readonly bool $enableStats) {}

    /**
     * @param Request $request
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function overviewAction(Request $request): Response
    {
        $jobFilter = JobFilter::fromRequest($request);

        $qb = $this->getEm()->createQueryBuilder();
        $qb->select('j')->from('JMSJobQueueBundle:Job', 'j')
            ->where($qb->expr()->isNull('j.originalJob'))
            ->orderBy('j.id', 'desc');

        $lastJobsWithError = $jobFilter->isDefaultPage() ? $this->jobManager->findLastJobsWithError(5) : [];
        foreach ($lastJobsWithError as $i => $job) {
            $qb->andWhere($qb->expr()->neq('j.id', '?'.$i));
            $qb->setParameter($i, $job->getId());
        }

        if (!empty($jobFilter->command)) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('j.command', ':commandQuery'),
                $qb->expr()->like('j.args', ':commandQuery')
            ))
                ->setParameter('commandQuery', '%'.$jobFilter->command.'%');
        }

        if (!empty($jobFilter->state)) {
            $qb->andWhere($qb->expr()->eq('j.state', ':jobState'))
                ->setParameter('jobState', $jobFilter->state);
        }

        $perPage = 50;

        $query = $qb->getQuery();
        $query->setMaxResults($perPage + 1);
        $query->setFirstResult(($jobFilter->page - 1) * $perPage);

        $jobs = $query->getResult();

        return new Response($this->twig->render('@JMSJobQueue/Job/overview.html.twig', array(
            'jobsWithError' => $lastJobsWithError,
            'jobs' => array_slice($jobs, 0, $perPage),
            'jobFilter' => $jobFilter,
            'hasMore' => count($jobs) > $perPage,
            'jobStates' => Job::getStates(),
        )));
    }

    /**
     * @param Job $job
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function detailsAction(Job $job): Response
    {
        $relatedEntities = array();
        foreach ($job->getRelatedEntities() as $entity) {
            $class = ClassUtils::getClass($entity);
            $relatedEntities[] = array(
                'class' => $class,
                'id' => json_encode($this->managerRegistry->getManagerForClass($class)->getClassMetadata($class)->getIdentifierValues($entity)),
                'raw' => $entity,
            );
        }

        $statisticData = $statisticOptions = array();
        if ($this->enableStats) {
            $dataPerCharacteristic = array();
            foreach ($this->managerRegistry->getManagerForClass(Job::class)->getConnection()->query("SELECT * FROM jms_job_statistics WHERE job_id = ".$job->getId()) as $row) {
                $dataPerCharacteristic[$row['characteristic']][] = array(
                    // hack because postgresql lower-cases all column names.
                    array_key_exists('createdAt', $row) ? $row['createdAt'] : $row['createdat'],
                    array_key_exists('charValue', $row) ? $row['charValue'] : $row['charvalue'],
                );
            }

            if ($dataPerCharacteristic) {
                $statisticData = array(array_merge(array('Time'), $chars = array_keys($dataPerCharacteristic)));
                $startTime = strtotime($dataPerCharacteristic[$chars[0]][0][0]);
                $endTime = strtotime($dataPerCharacteristic[$chars[0]][count($dataPerCharacteristic[$chars[0]])-1][0]);
                $scaleFactor = $endTime - $startTime > 300 ? 1/60 : 1;

                // This assumes that we have the same number of rows for each characteristic.
                for ($i = 0,$c = count(reset($dataPerCharacteristic)); $i < $c; $i++) {
                    $row = array((strtotime($dataPerCharacteristic[$chars[0]][$i][0]) - $startTime) * $scaleFactor);
                    foreach ($chars as $name) {
                        $value = (float) $dataPerCharacteristic[$name][$i][1];

                        if ($name == 'memory') {
                            $value /= 1024 * 1024;
                        }

                        $row[] = $value;
                    }

                    $statisticData[] = $row;
                }
            }
        }

        return new Response($this->twig->render('@JMSJobQueue/Job/details.html.twig', [
            'job' => $job,
            'relatedEntities' => $relatedEntities,
            'incomingDependencies' => $this->jobManager->getIncomingDependencies($job),
            'statisticData' => $statisticData,
            'statisticOptions' => $statisticOptions
        ]));
    }

    /**
     * @param Job $job
     * @return RedirectResponse
     */
    public function retryJobAction(Job $job): RedirectResponse
    {
        $state = $job->getState();

        if (
            Job::STATE_FAILED !== $state &&
            Job::STATE_TERMINATED !== $state &&
            Job::STATE_INCOMPLETE !== $state
        ) {
            throw new HttpException(400, 'Given job can\'t be retried');
        }

        $retryJob = clone $job;

        $this->getEm()->persist($retryJob);
        $this->getEm()->flush();

        $url = $this->router->generate('jms_jobs_details', array('id' => $retryJob->getId()));

        return new RedirectResponse($url, 201);
    }

    private function getEm(): ObjectManager
    {
        return $this->managerRegistry->getManagerForClass(Job::class);
    }
}
