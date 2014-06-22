<?php

/**
 * This file is part of the SgDatatablesBundle package.
 *
 * (c) stwe <https://github.com/stwe/DatatablesBundle>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sg\DatatablesBundle\Datatable\Data;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use Exception;

/**
 * Class DatatableData
 *
 * @package Sg\DatatablesBundle\Datatable\Data
 */
class DatatableData implements DatatableDataInterface
{
    /**
     * @var array
     */
    protected $requestParams;

    /**
     * @var ClassMetadata
     */
    protected $metadata;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var Serializer
     */
    protected $serializer;

    /**
     * @var DatatableQuery
     */
    protected $datatableQuery;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var mixed
     */
    protected $rootEntityIdentifier;

    /**
     * @var array
     */
    protected $response;

    /**
     * @var array
     */
    protected $selectColumns;

    /**
     * @var array
     */
    protected $allColumns;

    /**
     * @var array
     */
    protected $joins;

    /**
     * @var callable
     */
    protected $lineFormatter;

    //-------------------------------------------------
    // Ctor.
    //-------------------------------------------------

    /**
     * Ctor.
     *
     * @param array          $requestParams  All request params
     * @param ClassMetadata  $metadata       A ClassMetadata instance
     * @param EntityManager  $em             A EntityManager instance
     * @param Serializer     $serializer     A Serializer instance
     * @param DatatableQuery $datatableQuery A DatatableQuery instance
     */
    public function __construct(array $requestParams, ClassMetadata $metadata, EntityManager $em, Serializer $serializer, DatatableQuery $datatableQuery)
    {
        $this->requestParams = $requestParams;
        $this->metadata = $metadata;
        $this->em = $em;
        $this->serializer = $serializer;
        $this->tableName = $metadata->getTableName();
        $this->datatableQuery = $datatableQuery;
        $identifiers = $this->metadata->getIdentifierFieldNames();
        $this->rootEntityIdentifier = array_shift($identifiers);
        $this->response = array();
        $this->selectColumns = array();
        $this->allColumns = array();
        $this->joins = array();

        $this->prepareColumns();
    }


    //-------------------------------------------------
    // Private
    //-------------------------------------------------

    /**
     * Add an entry to the joins[] array.
     *
     * @param array $join
     *
     * @return $this
     */
    private function addJoin(array $join)
    {
        $this->joins[] = $join;

        return $this;
    }

    /**
     * Add an entry to the selectColumns[] array.
     *
     * @param ClassMetadata $metadata        A ClassMetadata instance
     * @param string        $column          The name of the column
     * @param null|string   $columnTableName The name of the column table
     *
     * @throws Exception
     * @return $this
     */
    private function addSelectColumn(ClassMetadata $metadata, $column, $columnTableName = null)
    {
        if (in_array($column, $metadata->getFieldNames())) {
            $this->selectColumns[($columnTableName?:$metadata->getTableName())][] = $column;
        } else {
            throw new Exception("Exception when parsing the columns.");
        }

        return $this;
    }

    /**
     * Set associations in joins[].
     *
     * @param array         $associationParts An array of the association parts
     * @param int           $i                Numeric key
     * @param ClassMetadata $metadata         A ClassMetadata instance
     * @param null|string   $columnTableName  The name of the column table
     *
     * @return $this
     */
    private function setAssociations(array $associationParts, $i, ClassMetadata $metadata, $columnTableName = null)
    {
        $column = $associationParts[$i];

        if ($metadata->hasAssociation($column) === true) {
            $targetClass = $metadata->getAssociationTargetClass($column);
            $targetMeta = $this->em->getClassMetadata($targetClass);
            $targetTableName = $targetMeta->getTableName();
            $targetIdentifiers = $targetMeta->getIdentifierFieldNames();
            $targetRootIdentifier = array_shift($targetIdentifiers);
            $columnTableName = $targetTableName . '_' . $column;
            if (!array_key_exists($targetTableName . '_' . $column, $this->selectColumns)) {
                $this->addSelectColumn($targetMeta, $targetRootIdentifier, $columnTableName);

                $this->addJoin(
                    array(
                        "source" => $metadata->getTableName() . '.' . $column,
                        "target" => $columnTableName
                    )
                );
            }

            $i++;
            $this->setAssociations($associationParts, $i, $targetMeta, $columnTableName);
        } else {
            $targetIdentifiers = $metadata->getIdentifierFieldNames();
            $targetRootIdentifier = array_shift($targetIdentifiers);

            if ($column !== $targetRootIdentifier) {
                $this->addSelectColumn($metadata, $column, $columnTableName);
            }

            $this->allColumns[] = $columnTableName . '.' . $column;
        }

        return $this;
    }

    /**
     * Prepare selectColumns[], allColumns[] and joins[].
     *
     * @return $this
     */
    private function prepareColumns()
    {
        // start with the tableName and the primary key e.g. 'fos_user' and 'id'
        $this->addSelectColumn($this->metadata, $this->rootEntityIdentifier);

        for ($i = 0; $i <= $this->requestParams["dql_counter"]; $i++) {

            if ($this->requestParams["dql_" . $i] != null) {
                $column = $this->requestParams["dql_" . $i];

                // association delimiter found (e.g. 'posts.comments.title')?
                if (strstr($column, '.') !== false) {
                    $array = explode('.', $column);
                    $this->setAssociations($array, 0, $this->metadata);
                } else {
                    // no association found
                    if ($column !== $this->rootEntityIdentifier) {
                        $this->addSelectColumn($this->metadata, $column);
                    }

                    $this->allColumns[] = $this->tableName . '.' . $column;
                }
            }

        }

        return $this;
    }

    /**
     * Set columns.
     *
     * @return $this
     */
    private function setColumns()
    {
        $this->datatableQuery->setSelectColumns($this->selectColumns);
        $this->datatableQuery->setAllColumns($this->allColumns);
        $this->datatableQuery->setJoins($this->joins);

        return $this;
    }

    /**
     * Build query.
     *
     * @return $this
     */
    private function buildQuery()
    {
        $this->datatableQuery->setSelectFrom();
        $this->datatableQuery->setLeftJoins($this->datatableQuery->getQb());
        $this->datatableQuery->setWhere($this->datatableQuery->getQb());
        $this->datatableQuery->setWhereCallbacks($this->datatableQuery->getQb());
        $this->datatableQuery->setOrderBy();
        $this->datatableQuery->setLimit();

        return $this;
    }

    /**
     * Set the line formatter function
     * 
     * @var callable
     * @return $this;
     */
    public function setLineFormatter(callable $lineFormatter = null)
    {
        $this->lineFormatter = $lineFormatter;

        return $this;        
    }


    //-------------------------------------------------
    // DatatableDataInterface
    //-------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function getResponse()
    {
        $this->setColumns();
        $this->buildQuery();

        $fresults = new Paginator($this->datatableQuery->execute(), true);
        $output = array("data" => array());

        foreach ($fresults as $item) {
            if (is_callable($this->lineFormatter)) {
                $callable = $this->lineFormatter;
                $item = call_user_func($callable, $item);
            }
            $output["data"][] = $item;
        }

        $outputHeader = array(
            "draw" => (int) $this->requestParams["draw"],
            "recordsTotal" => $this->datatableQuery->getCountAllResults($this->rootEntityIdentifier),
            "recordsFiltered" => $this->datatableQuery->getCountFilteredResults($this->rootEntityIdentifier)
        );

        $this->response = array_merge($outputHeader, $output);

        $json = $this->serializer->serialize($this->response, "json");
        $response = new Response($json);
        $response->headers->set("Content-Type", "application/json");

        return $response;
    }


    //-------------------------------------------------
    // Public
    //-------------------------------------------------

    /**
     * Add a callback function.
     *
     * @param string $callback
     *
     * @throws Exception
     * @return DatatableData
     */
    public function addWhereBuilderCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new Exception("The callback argument must be callable.");
        }

        $this->datatableQuery->addCallback($callback);

        return $this;
    }
}
