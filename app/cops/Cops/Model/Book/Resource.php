<?php
/*
 * This file is part of Silex Cops. Licensed under WTFPL
 *
 * (c) Mathieu Duplouy <mathieu.duplouy@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Cops\Model\Book;

use Cops\Model\ResourceAbstract;
use Cops\Exception\BookException;
use Cops\Model\Core;
use Cops\Model\Book\Collection;
use PDO;
use Doctrine\DBAL\Connection;

/**
 * Book resource model
 * @author Mathieu Duplouy <mathieu.duplouy@gmail.com>
 */
class Resource extends ResourceAbstract
{
    /**
     * Allow book exclusion
     * @var bool
     */
    private $_hasExcludedBook = false;

    /**
     * Book id to be excluded from statement
     * @var int
     */
    private $_excludeBookId;

    /**
     * Allow serie exclusion
     * @var bool
     */
    private $_hasExcludedSerie = false;

    /**
     * Serie id to be excluded
     * @var int
     */
    private $_excludeSerieId;

    /**
     * Load a book data
     *
     * @param  int              $bookId
     * @param  \Cops\Model\Book $book
     *
     * @return array
     */
    public function load($bookId)
    {
        $result = $this->getBaseSelect()
            ->where('main.id = :book_id')
            ->setParameter('book_id', $bookId, PDO::PARAM_INT)
            ->execute()
            ->fetch(PDO::FETCH_ASSOC);

        if (empty($result)) {
            throw new BookException(sprintf('Book width id %s not found', $bookId));
        }

        return $result;
    }

    /**
     * Load latest added books from database
     *
     * @param  int   $nb  Number of items to load
     *
     * @return array
     */
    public function loadLatest($nb)
    {
        return $this->getBaseSelect()
            ->orderBy('main.timestamp', 'DESC')
            ->setMaxResults($nb)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load books by serie ID
     *
     * @param  int          $serieId
     *
     * @return array
     */
    public function loadBySerieId($serieId)
    {
        return $this->getBaseSelect()
            ->andWhere('serie.id = :serie_id')
            ->orderBy('serie.name')
            ->addOrderBy('series_index')
            ->addOrderBy('title')
            ->setParameter('serie_id', $serieId)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load books by author ID
     *
     * @param int              $authorId
     * @param bool             $addFiles
     *
     * @return array
     */
    public function loadByAuthorId($authorId)
    {
        return $this->getBaseSelect()
            ->andWhere('author.id = :author_id')
            ->orderBy('serie.name')
            ->addOrderBy('series_index')
            ->addOrderBy('title')
            ->setParameter('author_id', $authorId)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load books collection by tag ID
     *
     * @param int              $tagId
     *
     * @return array
     */
    public function loadByTagId($tagId)
    {
        $qb = $this->getBaseSelect()
            ->innerJoin('main', 'books_tags_link', 'btl', 'main.id = btl.book')
            ->innerJoin('main', 'tags'           , 'tag', 'tag.id  = btl.tag')
            ->andWhere('tag.id = :tagid')
            ->orderBy('serie.name')
            ->addOrderBy('series_index')
            ->addOrderBy('author_sort')
            ->addOrderBy('title')
            ->setParameter('tagid', $tagId, PDO::PARAM_INT);

        // Count total rows when using limit
        if ($this->maxResults !=null) {
            $countQuery = clone($qb);

            $total = (int) $countQuery
                ->resetQueryParts(array('select', 'groupBy', 'orderBy'))
                ->select('COUNT(*)')
                ->execute()
                ->fetchColumn();

            $this->totalRows = $total;

            $qb->setFirstResult($this->firstResult)
                ->setMaxResults($this->maxResults);
        }

        return $qb->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load collection based on keyword
     *
     * @param  array        $keywords
     *
     * @return array
     */
    public function loadByKeyword($keywords) {

        $qb = $this->getBaseSelect()
            ->leftJoin('main', 'books_tags_link', 'btl', 'btl.book = main.id')
            ->leftJoin('main',  'tags',           'tag', 'tag.id = btl.tag')
            ->orderBy('serie_name')
            ->addOrderBy('series_index')
            ->addOrderBy('author_name')
            ->addOrderBy('title')
            ->groupBy('main.id');

        // Build the where clause
        $and = $qb->expr()->andX();
        foreach ($keywords as $keyword) {
            $and->add(
                $qb->expr()->Like('main.path', $this->getConnection()->quote('%'.$keyword.'%'))
            );
        }

        $qb->where($and);

        // Count total rows when using limit
        if ($this->maxResults !=null) {
            $countQuery = clone($qb);

            $total = (int) $countQuery
                ->resetQueryParts(array('select', 'join', 'groupBy', 'orderBy'))
                ->select('COUNT(*)')
                ->execute()
                ->fetchColumn();

            $this->totalRows = $total;

            $qb->setFirstResult($this->firstResult)
                ->setMaxResults($this->maxResults);
        }

        return $qb->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set data from statement
     *
     * @param array            $result The result array from select stmt
     *
     * @return array
     */
    public function setDataFromStatement(array $result)
    {
        $myBook = parent::setDataFromStatement($result);

        $myBook->getAuthor()->setData(array(
            'id'   => $result['author_id'],
            'name' => $result['author_name'],
            'sort' => $result['author_sort'],
        ));

        if (!empty($result['serie_id'])) {
            $myBook->getSerie()->setData(array(
                'id'   => $result['serie_id'],
                'name' => $result['serie_name'],
                'sort' => $result['serie_sort'],
            ));
        }

        return $myBook;
    }

    /**
     * Define excluded book id
     *
     * @param int $id
     *
     * @return Resource
     */
    public function setExcludedBookId($id) {
        $this->_hasExcludedBook = true;
        $this->_excludeBookId = (int) $id;
        return $this;
    }

    /**
     * Define excluded serie id
     *
     * @param int $id
     *
     * @return Resource
     */
    public function setExcludedSerieId($id) {
        $this->_hasExcludedSerie = true;
        $this->_excludeSerieId = (int) $id;
        return $this;
    }

    /**
     * Get the base select from QueryBuilder
     *
     * @return Doctrine\DBAL\Query\QueryBuilder
     */
    protected function getBaseSelect()
    {
        $qb = parent::getBaseSelect()
            ->select(
                'main.*',
                'com.text AS comment',
                'rating.rating AS rating',
                'author.id AS author_id',
                'author.name AS author_name',
                'author.sort AS author_sort',
                'serie.id AS serie_id',
                'serie.name AS serie_name',
                'serie.sort AS serie_sort'
            )
            ->from('books', 'main')
            ->leftJoin('main', 'comments',           'com',    'com.book = main.id')
            ->leftJoin('main', 'books_authors_link', 'bal',    'bal.book = main.id')
            ->leftJoin('main', 'authors',            'author', 'author.id = bal.author')
            ->leftJoin('main', 'books_series_link',  'bsl',    'bsl.book = main.id')
            ->leftJoin('main', 'series',             'serie',  'serie.id = bsl.series')
            ->leftJoin('main', 'books_ratings_link', 'brl',    'brl.book = main.id')
            ->leftJoin('main', 'ratings'           , 'rating', 'brl.rating = rating.id')
            ->where('1');

        if ($this->_hasExcludedBook) {
            $qb->andWhere('main.id != :exclude_book')
                ->setParameter('exclude_book', $this->_excludeBookId);
            $this->_hasExcludedBook = false;
            $this->_excludeBookId = null;
        }
        if ($this->_hasExcludedSerie) {
            $qb->andWhere('serie.id IS NULL OR serie.id != :exclude_serie')
                ->setParameter('exclude_serie', $this->_excludeSerieId);
            $this->_hasExcludedSerie = false;
            $this->_excludeSerieId = null;
        }
        return $qb;
    }
}

