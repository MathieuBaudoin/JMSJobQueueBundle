<?php

namespace JMS\JobQueueBundle\EventListener;

use ArrayIterator;
use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\ClosureExpressionVisitor;
use Doctrine\Common\Collections\Selectable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use JMS\JobQueueBundle\Entity\Job;
use Traversable;

/**
 * Collection for persistent related entities.
 *
 * We do not support all of Doctrine's built-in features.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class PersistentRelatedEntitiesCollection implements Collection, Selectable
{
    private ManagerRegistry $registry;
    private Job $job;
    private ?array $entities = null;

    public function __construct(ManagerRegistry $registry, Job $job)
    {
        $this->registry = $registry;
        $this->job = $job;
    }

    /**
     * Gets the PHP array representation of this collection.
     *
     * @return array|null The PHP array representation of this collection.
     * @throws Exception
     */
    public function toArray(): ?array
    {
        $this->initialize();

        return $this->entities;
    }

    /**
     * Sets the internal iterator to the first element in the collection and
     * returns this element.
     *
     * @return object|false
     * @throws Exception
     */
    public function first(): object|bool
    {
        $this->initialize();

        return reset($this->entities);
    }

    /**
     * Sets the internal iterator to the last element in the collection and
     * returns this element.
     *
     * @return object|false
     * @throws Exception
     */
    public function last(): object|bool
    {
        $this->initialize();

        return end($this->entities);
    }

    /**
     * Gets the current key/index at the current internal iterator position.
     *
     * @return string|integer
     * @throws Exception
     */
    public function key(): int|string
    {
        $this->initialize();

        return key($this->entities);
    }

    /**
     * Moves the internal iterator position to the next element.
     *
     * @return object|false
     * @throws Exception
     */
    public function next(): object|bool
    {
        $this->initialize();

        return next($this->entities);
    }

    /**
     * Gets the element of the collection at the current internal iterator position.
     *
     * @return object|false
     * @throws Exception
     */
    public function current(): object|bool
    {
        $this->initialize();

        return current($this->entities);
    }

    /**
     * Removes an element with a specific key/index from the collection.
     *
     * @param integer|string $key
     * @return object|null The removed element or NULL, if no element exists for the given key.
     */
    public function remove(int|string $key): ?object
    {
        throw new LogicException('remove() is not supported.');
    }

    /**
     * Removes the specified element from the collection, if it is found.
     *
     * @param object $element The element to remove.
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeElement($element): bool
    {
        throw new LogicException('removeElement() is not supported.');
    }

    /**
     * ArrayAccess implementation of offsetExists()
     *
     * @param mixed $offset
     * @return bool
     * @throws Exception
     * @see containsKey()
     *
     */
    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return $this->containsKey($offset);
    }

    /**
     * ArrayAccess implementation of offsetGet()
     *
     * @param mixed $offset
     * @return mixed
     * @throws Exception
     * @see get()
     *
     */
    public function offsetGet(mixed $offset): object
    {
        $this->initialize();

        return $this->get($offset);
    }

    /**
     * ArrayAccess implementation of offsetSet()
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     * @see set()
     *
     * @see add()
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Adding new related entities is not supported after initial creation.');
    }

    /**
     * ArrayAccess implementation of offsetUnset()
     *
     * @param mixed $offset
     * @return void
     * @see remove()
     *
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('unset() is not supported.');
    }

    /**
     * Checks whether the collection contains a specific key/index.
     *
     * @param mixed $key The key to check for.
     * @return boolean TRUE if the given key/index exists, FALSE otherwise.
     * @throws Exception
     */
    public function containsKey($key): bool
    {
        $this->initialize();

        return isset($this->entities[$key]);
    }

    /**
     * Checks whether the given element is contained in the collection.
     * Only element values are compared, not keys. The comparison of two elements
     * is strict, that means not only the value but also the type must match.
     * For objects this means reference equality.
     *
     * @param mixed $element
     * @return boolean TRUE if the given element is contained in the collection,
     *          FALSE otherwise.
     * @throws Exception
     */
    public function contains(mixed $element): bool
    {
        $this->initialize();

        foreach ($this->entities as $collectionElement) {
            if ($element === $collectionElement) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tests for the existence of an element that satisfies the given predicate.
     *
     * @param Closure $p The predicate.
     * @return boolean TRUE if the predicate is TRUE for at least one element, FALSE otherwise.
     * @throws Exception
     */
    public function exists(Closure $p): bool
    {
        $this->initialize();

        foreach ($this->entities as $key => $element) {
            if ($p($key, $element)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Searches for a given element and, if found, returns the corresponding key/index
     * of that element. The comparison of two elements is strict, that means not
     * only the value but also the type must match.
     * For objects this means reference equality.
     *
     * @param mixed $element The element to search for.
     * @return string|int|bool The key/index of the element or FALSE if the element was not found.
     * @throws Exception
     */
    public function indexOf(mixed $element): string|int|bool
    {
        $this->initialize();

        return array_search($element, $this->entities, true);
    }

    /**
     * Gets the element with the given key/index.
     *
     * @param mixed $key The key.
     * @return mixed The element or NULL, if no element exists for the given key.
     * @throws Exception
     */
    public function get($key): mixed
    {
        $this->initialize();

        if (isset($this->entities[$key])) {
            return $this->entities[$key];
        }
        return null;
    }

    /**
     * Gets all keys/indexes of the collection elements.
     *
     * @return array
     * @throws Exception
     */
    public function getKeys(): array
    {
        $this->initialize();

        return array_keys($this->entities);
    }

    /**
     * Gets all elements.
     *
     * @return array
     * @throws Exception
     */
    public function getValues(): array
    {
        $this->initialize();

        return array_values($this->entities);
    }

    /**
     * Returns the number of elements in the collection.
     *
     * Implementation of the Countable interface.
     *
     * @return integer The number of elements in the collection.
     * @throws Exception
     */
    public function count(): int
    {
        $this->initialize();

        return count($this->entities);
    }

    /**
     * Adds/sets an element in the collection at the index / with the specified key.
     *
     * When the collection is a Map this is like put(key,value)/add(key,value).
     * When the collection is a List this is like add(position,value).
     *
     * @param mixed $key
     * @param mixed $value
     */
    public function set($key, mixed $value): never
    {
        throw new LogicException('set() is not supported.');
    }

    /**
     * Adds an element to the collection.
     *
     * @param mixed $element
     * @return never Always TRUE.
     */
    public function add(mixed $element): never
    {
        throw new LogicException('Adding new entities is not supported after creation.');
    }

    /**
     * Checks whether the collection is empty.
     *
     * Note: This is preferable over count() == 0.
     *
     * @return boolean TRUE if the collection is empty, FALSE otherwise.
     * @throws Exception
     */
    public function isEmpty(): bool
    {
        $this->initialize();

        return ! $this->entities;
    }

    /**
     * Gets an iterator for iterating over the elements in the collection.
     *
     * @return ArrayIterator
     * @throws Exception
     */
    public function getIterator(): Traversable
    {
        $this->initialize();

        return new ArrayIterator($this->entities);
    }

    /**
     * Applies the given function to each element in the collection and returns
     * a new collection with the elements returned by the function.
     *
     * @param Closure $func
     * @return ArrayCollection|Collection
     * @throws Exception
     */
    public function map(Closure $func): ArrayCollection|Collection
    {
        $this->initialize();

        return new ArrayCollection(array_map($func, $this->entities));
    }

    /**
     * Returns all the elements of this collection that satisfy the predicate p.
     * The order of the elements is preserved.
     *
     * @param Closure $p The predicate used for filtering.
     * @return ArrayCollection|Collection A collection with the results of the filter operation.
     * @throws Exception
     */
    public function filter(Closure $p): ArrayCollection|Collection
    {
        $this->initialize();

        return new ArrayCollection(array_filter($this->entities, $p));
    }

    /**
     * Applies the given predicate p to all elements of this collection,
     * returning true, if the predicate yields true for all elements.
     *
     * @param Closure $p The predicate.
     * @return boolean TRUE, if the predicate yields TRUE for all elements, FALSE otherwise.
     * @throws Exception
     */
    public function forAll(Closure $p): bool
    {
        $this->initialize();

        foreach ($this->entities as $key => $element) {
            if ( ! $p($key, $element)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Partitions this collection in two collections according to a predicate.
     * Keys are preserved in the resulting collections.
     *
     * @param Closure $p The predicate on which to partition.
     * @return array An array with two elements. The first element contains the collection
     *               of elements where the predicate returned TRUE, the second element
     *               contains the collection of elements where the predicate returned FALSE.
     * @throws Exception
     */
    public function partition(Closure $p): array
    {
        $this->initialize();

        $coll1 = $coll2 = array();
        foreach ($this->entities as $key => $element) {
            if ($p($key, $element)) {
                $coll1[$key] = $element;
            } else {
                $coll2[$key] = $element;
            }
        }
        return array(new ArrayCollection($coll1), new ArrayCollection($coll2));
    }

    /**
     * Returns a string representation of this object.
     *
     * @return string
     */
    public function __toString()
    {
        return __CLASS__ . '@' . spl_object_hash($this);
    }

    /**
     * Clears the collection.
     */
    public function clear()
    {
        throw new LogicException('clear() is not supported.');
    }

    /**
     * Extract a slice of $length elements starting at position $offset from the Collection.
     *
     * If $length is null it returns all elements from $offset to the end of the Collection.
     * Keys have to be preserved by this method. Calling this method will only return the
     * selected slice and NOT change the elements contained in the collection slice is called on.
     *
     * @param int $offset
     * @param int|null $length
     * @return array
     * @throws Exception
     */
    public function slice(int $offset, int|null $length = null): array
    {
        $this->initialize();

        return array_slice($this->entities, $offset, $length, true);
    }

    /**
     * Select all elements from a selectable that match the criteria and
     * return a new collection containing these elements.
     *
     * @param Criteria $criteria
     * @return ArrayCollection|Collection
     * @throws Exception
     */
    public function matching(Criteria $criteria): ArrayCollection|Collection
    {
        $this->initialize();

        $expr     = $criteria->getWhereExpression();
        $filtered = $this->entities;

        if ($expr) {
            $visitor  = new ClosureExpressionVisitor();
            $filter   = $visitor->dispatch($expr);
            $filtered = array_filter($filtered, $filter);
        }

        if (null !== $orderings = $criteria->orderings()) {
            $next = null;
            foreach (array_reverse($orderings) as $field => $ordering) {
                $next = ClosureExpressionVisitor::sortByField($field, $ordering == 'DESC' ? -1 : 1, $next);
            }

            usort($filtered, $next);
        }

        $offset = $criteria->getFirstResult();
        $length = $criteria->getMaxResults();

        if ($offset || $length) {
            $filtered = array_slice($filtered, (int)$offset, $length);
        }

        return new ArrayCollection($filtered);
    }

    /**
     * @throws Exception
     */
    private function initialize(): void
    {
        if (null !== $this->entities) {
            return;
        }

        /** @var Connection $con */
        $con = $this->registry->getManagerForClass(Job::class)->getConnection();
        $entitiesPerClass = array();
        $count = 0;

        foreach ($con->executeQuery("SELECT related_class, related_id FROM jms_job_related_entities WHERE job_id = ".$this->job->getId())->fetchAllAssociative() as $data) {
            $count += 1;
            $entitiesPerClass[$data['related_class']][] = json_decode($data['related_id'], true);
        }

        if (0 === $count) {
            $this->entities = array();

            return;
        }

        $entities = array();
        foreach ($entitiesPerClass as $className => $ids) {
            $em = $this->registry->getManagerForClass($className);
            $qb = $em->createQueryBuilder()
                        ->select('e')->from($className, 'e');

            $i = 0;
            foreach ($ids as $id) {
                $expr = null;
                foreach ($id as $k => $v) {
                    if (null === $expr) {
                        $expr = $qb->expr()->eq('e.'.$k, '?'.(++$i));
                    } else {
                        $expr = $qb->expr()->andX($expr, $qb->expr()->eq('e.'.$k, '?'.(++$i)));
                    }

                    $qb->setParameter($i, $v);
                }

                $qb->orWhere($expr);
            }

            $entities = array_merge($entities, $qb->getQuery()->getResult());
        }

        $this->entities = $entities;
    }

    public function findFirst(Closure $p)
    {
        // TODO: Implement findFirst() method.
    }

    public function reduce(Closure $func, mixed $initial = null)
    {
        // TODO: Implement reduce() method.
    }
}