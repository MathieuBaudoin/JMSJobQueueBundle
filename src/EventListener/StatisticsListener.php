<?php

namespace JMS\JobQueueBundle\EventListener;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\Mapping\MappingException;

class StatisticsListener
{
    /**
     * @param GenerateSchemaEventArgs $event
     * @return void
     * @throws MappingException
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();

        // When using multiple entity managers ignore events that are triggered by other entity managers.
        if ($event->getEntityManager()->getMetadataFactory()->isTransient('JMS\JobQueueBundle\Entity\Job')) {
            return;
        }

        $table = $schema->createTable('jms_job_statistics');
        $table->addColumn('job_id', 'bigint', array('notnull' => true, 'unsigned' => true));
        $table->addColumn('characteristic', 'string', array('length' => 30, 'notnull' => true));
        $table->addColumn('createdAt', 'datetime', array('notnull' => true));
        $table->addColumn('charValue', 'float', array('notnull' => true));
        $table->setPrimaryKey(array('job_id', 'characteristic', 'createdAt'));
    }
}