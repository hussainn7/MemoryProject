<?php

namespace App\Repository;

use App\Entity\QrCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QrCode>
 */
class QrCodeRepository extends ServiceEntityRepository implements DataTablesRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QrCode::class);
    }

    public function getQueryForDataTables(): QueryBuilder
    {
        return $this->createQueryBuilder('dt')
            ->leftJoin('dt.user', 'user')
            ->addSelect('user')
            ->leftJoin('dt.client', 'client')
            ->addSelect('client')
            ->leftJoin('dt.memory', 'memory')
            ->addSelect('memory')
            ->leftJoin('dt.status', 'status')
            ->addSelect('status');
    }

    /**
     * Get query for DataTables filtered by creator (user who created the QR code)
     */
    public function getQueryForDataTablesByCreator($creatorUser): QueryBuilder
    {
        return $this->createQueryBuilder('dt')
            ->leftJoin('dt.user', 'user')
            ->addSelect('user')
            ->leftJoin('dt.client', 'client')
            ->addSelect('client')
            ->leftJoin('dt.memory', 'memory')
            ->addSelect('memory')
            ->leftJoin('dt.status', 'status')
            ->addSelect('status')
            ->where('dt.user = :creator')
            ->setParameter('creator', $creatorUser);
    }

    public function checkLabel($label)
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.label = :label')
            ->setParameter('label', $label)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return QrCode[] Returns an array of QrCode objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('q.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?QrCode
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
