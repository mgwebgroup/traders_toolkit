<?php
/**
 * This file is part of the Trade Helper Online package.
 *
 * (c) 2019-2020  Alex Kay <alex110504@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Exchange;

/**
 * Class DailyIterator
 * Simple iterator to iterate between START and END dates in both directions
 * @package App\Service\Exchange
 */
class DailyIterator implements \Iterator
{
    const INTERVAL = 'P1D';
    /**
     * '2000-01-01'
     */
    const START = 946684800;

    /**
     * '2100-12-31'
     */
    const END = 4133894400;

    /**
     * UNIX Timestamp
     * @var int
     */
    protected $lowerLimit;

    /**
     * UNIX Timestamp
     * @var int
     */
    protected $upperLimit;

    /** @var \DateTime */
    protected $date;

    /**
     * Stores start date for the rewind method
     * @var \DateTime
     */
    protected $startDate;

    /** @var integer */
    protected $direction;

    public function __construct($start = null, $end = null)
    {
        $this->lowerLimit = (is_numeric($start) && $start > 0)? $start : self::START;
        $this->upperLimit = (is_numeric($end) && $end > 0)? $end : self::END;
        $this->direction = 1;
    }
    /**
     * @inheritDoc
     */
    public function current()
    {
        return $this->date;
    }

    /**
     * @inheritDoc
     */
    public function next()
    {
        if ($this->direction > 0) {
            $this->date->add(new \DateInterval(self::INTERVAL));
        } else {
            $this->date->sub(new \DateInterval(self::INTERVAL));
        }
    }

    /**
     * @inheritDoc
     */
    public function key()
    {
        return $this->date->format('Ymd');
    }

    /**
     * @inheritDoc
     */
    public function valid()
    {
        if ($this->date instanceof \DateTime) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function rewind()
    {
        if ($this->startDate === null) {
            if ($this->direction > 0) {
                $this->date = new \DateTime('@'.$this->lowerLimit);
            } else {
                $this->date = new \DateTime('@'.$this->upperLimit);
            }
        } else {
            $this->date = clone $this->startDate;
        }

    }

    /**
     * @param integer $direction
     * @throws Exception
     */
    public function setDirection($direction)
    {
        if (is_numeric($direction)) {
            if ($direction > 0) {
                $this->direction = 1;
            } else {
                $this->direction = -1;
            }
        } else {
            throw new \Exception('Value of direction must be numeric');
        }

        return $this;
    }

    /**
     * @param \DateTime $date
     */
    public function setStartDate($date)
    {
        if ($date instanceof \DateTime) {
            if ($date->getTimestamp() < $this->lowerLimit) {
                throw new Exception(sprintf('Date is older than %s', date($this->lowerLimit, 'c')));
            }
            if ($date->getTimestamp() > $this->upperLimit) {
                throw new \Exception(sprintf('Date is newer that %s', date($this->upperLimit, 'c')));
            }
            $this->startDate = $date;
        } else {
            throw new \Exception('Date must be instance of \DateTime');
        }

        return $this;
    }
}