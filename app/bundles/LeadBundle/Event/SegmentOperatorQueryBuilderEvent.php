<?php

declare(strict_types=1);

/*
 * @copyright   2020 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\Query\Expression\CompositeExpression;
use Mautic\LeadBundle\Segment\Query\QueryBuilder;
use Symfony\Component\EventDispatcher\Event;

final class SegmentOperatorQueryBuilderEvent extends Event
{
    /**
     * @var QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var ContactSegmentFilter
     */
    private $filter;

    /**
     * @var string|string[]
     */
    private $parameterHolder;

    /**
     * @var bool
     */
    private $operatorHandled = false;

    /**
     * @param string|string[] $parameterHolder
     */
    public function __construct(QueryBuilder $queryBuilder, ContactSegmentFilter $filter, $parameterHolder)
    {
        $this->queryBuilder    = $queryBuilder;
        $this->filter          = $filter;
        $this->parameterHolder = $parameterHolder;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function getFilter(): ContactSegmentFilter
    {
        return $this->filter;
    }

    /**
     * @return string|string[]
     */
    public function getParameterHolder()
    {
        return $this->parameterHolder;
    }

    public function operatorIsOneOf(string ...$operators): bool
    {
        return in_array($this->filter->getOperator(), $operators, true);
    }

    /**
     * @param CompositeExpression|string $expression
     */
    public function addExpression($expression): void
    {
        $this->queryBuilder->addLogic($expression, $this->filter->getGlue());

        $this->setOperatorHandled(true);
    }

    /**
     * The subscriber must tell the event that the operator was successfully handled.
     * Otherwise an exception will be thrown as an unknown operator was sent.
     * Or use the addExpression() method that will set it automatically.
     */
    public function setOperatorHandled(bool $wasHandled): void
    {
        $this->operatorHandled = $wasHandled;
    }

    public function wasOperatorHandled(): bool
    {
        return $this->operatorHandled;
    }
}
