<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Happyr\DoctrineSpecification\Repository\EntitySpecificationRepository;
use Happyr\DoctrineSpecification\Specification\Specification;
use Shlinkio\Shlink\Common\Doctrine\Type\ChronosDateTimeType;
use Shlinkio\Shlink\Common\Util\DateRange;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Model\ShortUrlIdentifier;
use Shlinkio\Shlink\Core\Model\ShortUrlMeta;
use Shlinkio\Shlink\Core\Model\ShortUrlsOrdering;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkUrl;

use function array_column;
use function count;
use function Functional\contains;

class ShortUrlRepository extends EntitySpecificationRepository implements ShortUrlRepositoryInterface
{
    /**
     * @param string[] $tags
     * @return ShortUrl[]
     */
    public function findList(
        ?int $limit = null,
        ?int $offset = null,
        ?string $searchTerm = null,
        array $tags = [],
        ?ShortUrlsOrdering $orderBy = null,
        ?DateRange $dateRange = null,
        ?Specification $spec = null,
    ): array {
        $qb = $this->createListQueryBuilder($searchTerm, $tags, $dateRange, $spec);
        $qb->select('DISTINCT s')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        // In case the ordering has been specified, the query could be more complex. Process it
        if ($orderBy?->hasOrderField()) {
            return $this->processOrderByForList($qb, $orderBy);
        }

        // With no order by, order by date and just return the list of ShortUrls
        $qb->orderBy('s.dateCreated');
        return $qb->getQuery()->getResult();
    }

    private function processOrderByForList(QueryBuilder $qb, ShortUrlsOrdering $orderBy): array
    {
        $fieldName = $orderBy->orderField();
        $order = $orderBy->orderDirection();

        // visitsCount and visitCount are deprecated. Only visits should work
        if (contains(['visits', 'visitsCount', 'visitCount'], $fieldName)) {
            // FIXME This query is inefficient. Debug it.
            $qb->addSelect('COUNT(DISTINCT v) AS totalVisits')
               ->leftJoin('s.visits', 'v')
               ->groupBy('s')
               ->orderBy('totalVisits', $order);

            return array_column($qb->getQuery()->getResult(), 0);
        }

        // Map public field names to column names
        $fieldNameMap = [
            'originalUrl' => 'longUrl', // Deprecated
            'longUrl' => 'longUrl',
            'shortCode' => 'shortCode',
            'dateCreated' => 'dateCreated',
            'title' => 'title',
        ];
        $resolvedFieldName = $fieldNameMap[$fieldName] ?? null;
        if ($resolvedFieldName !== null) {
            $qb->orderBy('s.' . $resolvedFieldName, $order);
        }

        return $qb->getQuery()->getResult();
    }

    public function countList(
        ?string $searchTerm = null,
        array $tags = [],
        ?DateRange $dateRange = null,
        ?Specification $spec = null,
    ): int {
        $qb = $this->createListQueryBuilder($searchTerm, $tags, $dateRange, $spec);
        $qb->select('COUNT(DISTINCT s)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function createListQueryBuilder(
        ?string $searchTerm,
        array $tags,
        ?DateRange $dateRange,
        ?Specification $spec,
    ): QueryBuilder {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->from(ShortUrl::class, 's')
           ->where('1=1');

        if ($dateRange?->startDate() !== null) {
            $qb->andWhere($qb->expr()->gte('s.dateCreated', ':startDate'));
            $qb->setParameter('startDate', $dateRange->startDate(), ChronosDateTimeType::CHRONOS_DATETIME);
        }
        if ($dateRange?->endDate() !== null) {
            $qb->andWhere($qb->expr()->lte('s.dateCreated', ':endDate'));
            $qb->setParameter('endDate', $dateRange->endDate(), ChronosDateTimeType::CHRONOS_DATETIME);
        }

        // Apply search term to every searchable field if not empty
        if (! empty($searchTerm)) {
            // Left join with tags only if no tags were provided. In case of tags, an inner join will be done later
            if (empty($tags)) {
                $qb->leftJoin('s.tags', 't');
            }

            // Apply search conditions
            $qb->leftJoin('s.domain', 'd')
               ->andWhere($qb->expr()->orX(
                   $qb->expr()->like('s.longUrl', ':searchPattern'),
                   $qb->expr()->like('s.shortCode', ':searchPattern'),
                   $qb->expr()->like('s.title', ':searchPattern'),
                   $qb->expr()->like('t.name', ':searchPattern'),
                   $qb->expr()->like('d.authority', ':searchPattern'),
               ))
               ->setParameter('searchPattern', '%' . $searchTerm . '%');
        }

        // Filter by tags if provided
        if (! empty($tags)) {
            $qb->join('s.tags', 't')
               ->andWhere($qb->expr()->in('t.name', $tags));
        }

        $this->applySpecification($qb, $spec, 's');

        return $qb;
    }

    public function findOneWithDomainFallback(string $shortCode, ?string $domain = null): ?ShortUrl
    {
        // When ordering DESC, Postgres puts nulls at the beginning while the rest of supported DB engines put them at
        // the bottom
        $dbPlatform = $this->getEntityManager()->getConnection()->getDatabasePlatform()->getName();
        $ordering = $dbPlatform === 'postgresql' ? 'ASC' : 'DESC';

        $dql = <<<DQL
            SELECT s
              FROM Shlinkio\Shlink\Core\Entity\ShortUrl AS s
         LEFT JOIN s.domain AS d
             WHERE s.shortCode = :shortCode
               AND (s.domain IS NULL OR d.authority = :domain)
          ORDER BY s.domain {$ordering}
        DQL;

        $query = $this->getEntityManager()->createQuery($dql);
        $query->setMaxResults(1)
              ->setParameters([
                  'shortCode' => $shortCode,
                  'domain' => $domain,
              ]);

        // Since we ordered by domain, we will have first the URL matching provided domain, followed by the one
        // with no domain (if any), so it is safe to fetch 1 max result and we will get:
        //  * The short URL matching both the short code and the domain, or
        //  * The short URL matching the short code but without any domain, or
        //  * No short URL at all

        return $query->getOneOrNullResult();
    }

    public function findOne(ShortUrlIdentifier $identifier, ?Specification $spec = null): ?ShortUrl
    {
        $qb = $this->createFindOneQueryBuilder($identifier, $spec);
        $qb->select('s');

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function shortCodeIsInUse(ShortUrlIdentifier $identifier, ?Specification $spec = null): bool
    {
        return $this->doShortCodeIsInUse($identifier, $spec, null);
    }

    public function shortCodeIsInUseWithLock(ShortUrlIdentifier $identifier, ?Specification $spec = null): bool
    {
        return $this->doShortCodeIsInUse($identifier, $spec, LockMode::PESSIMISTIC_WRITE);
    }

    private function doShortCodeIsInUse(ShortUrlIdentifier $identifier, ?Specification $spec, ?int $lockMode): bool
    {
        $qb = $this->createFindOneQueryBuilder($identifier, $spec)->select('s.id');
        $query = $qb->getQuery();

        if ($lockMode !== null) {
            $query = $query->setLockMode($lockMode);
        }

        return $query->getOneOrNullResult() !== null;
    }

    private function createFindOneQueryBuilder(ShortUrlIdentifier $identifier, ?Specification $spec): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->from(ShortUrl::class, 's')
           ->where($qb->expr()->isNotNull('s.shortCode'))
           ->andWhere($qb->expr()->eq('s.shortCode', ':slug'))
           ->setParameter('slug', $identifier->shortCode())
           ->setMaxResults(1);

        $this->whereDomainIs($qb, $identifier->domain());

        $this->applySpecification($qb, $spec, 's');

        return $qb;
    }

    public function findOneMatching(ShortUrlMeta $meta): ?ShortUrl
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('s')
           ->from(ShortUrl::class, 's')
           ->where($qb->expr()->eq('s.longUrl', ':longUrl'))
           ->setParameter('longUrl', $meta->getLongUrl())
           ->setMaxResults(1)
           ->orderBy('s.id');

        if ($meta->hasCustomSlug()) {
            $qb->andWhere($qb->expr()->eq('s.shortCode', ':slug'))
               ->setParameter('slug', $meta->getCustomSlug());
        }
        if ($meta->hasMaxVisits()) {
            $qb->andWhere($qb->expr()->eq('s.maxVisits', ':maxVisits'))
               ->setParameter('maxVisits', $meta->getMaxVisits());
        }
        if ($meta->hasValidSince()) {
            $qb->andWhere($qb->expr()->eq('s.validSince', ':validSince'))
               ->setParameter('validSince', $meta->getValidSince(), ChronosDateTimeType::CHRONOS_DATETIME);
        }
        if ($meta->hasValidUntil()) {
            $qb->andWhere($qb->expr()->eq('s.validUntil', ':validUntil'))
               ->setParameter('validUntil', $meta->getValidUntil(), ChronosDateTimeType::CHRONOS_DATETIME);
        }
        if ($meta->hasDomain()) {
            $qb->join('s.domain', 'd')
               ->andWhere($qb->expr()->eq('d.authority', ':domain'))
               ->setParameter('domain', $meta->getDomain());
        }

        $apiKey = $meta->getApiKey();
        if ($apiKey !== null) {
            $this->applySpecification($qb, $apiKey->spec(), 's');
        }

        $tags = $meta->getTags();
        $tagsAmount = count($tags);
        if ($tagsAmount === 0) {
            return $qb->getQuery()->getOneOrNullResult();
        }

        foreach ($tags as $index => $tag) {
            $alias = 't_' . $index;
            $qb->join('s.tags', $alias, Join::WITH, $alias . '.name = :tag' . $index)
               ->setParameter('tag' . $index, $tag);
        }

        // If tags where provided, we need an extra join to see the amount of tags that every short URL has, so that we
        // can discard those that also have more tags, making sure only those fully matching are included.
        $qb->join('s.tags', 't')
           ->groupBy('s')
           ->having($qb->expr()->eq('COUNT(t.id)', ':tagsAmount'))
           ->setParameter('tagsAmount', $tagsAmount);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findOneByImportedUrl(ImportedShlinkUrl $url): ?ShortUrl
    {
        $qb = $this->createQueryBuilder('s');
        $qb->andWhere($qb->expr()->eq('s.importOriginalShortCode', ':shortCode'))
           ->setParameter('shortCode', $url->shortCode())
           ->andWhere($qb->expr()->eq('s.importSource', ':importSource'))
           ->setParameter('importSource', $url->source())
           ->setMaxResults(1);

        $this->whereDomainIs($qb, $url->domain());

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function whereDomainIs(QueryBuilder $qb, ?string $domain): void
    {
        if ($domain !== null) {
            $qb->join('s.domain', 'd')
               ->andWhere($qb->expr()->eq('d.authority', ':authority'))
               ->setParameter('authority', $domain);
        } else {
            $qb->andWhere($qb->expr()->isNull('s.domain'));
        }
    }

    public function findCrawlableShortCodes(): iterable
    {
        $blockSize = 1000;
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('DISTINCT s.shortCode')
           ->from(ShortUrl::class, 's')
           ->where($qb->expr()->eq('s.crawlable', ':crawlable'))
           ->setParameter('crawlable', true)
           ->setMaxResults($blockSize);

        $page = 0;
        do {
            $qbClone = (clone $qb)->setFirstResult($blockSize * $page);
            $iterator = $qbClone->getQuery()->toIterable();
            $resultsFound = false;
            $page++;

            foreach ($iterator as ['shortCode' => $shortCode]) {
                $resultsFound = true;
                yield $shortCode;
            }
        } while ($resultsFound);
    }
}
