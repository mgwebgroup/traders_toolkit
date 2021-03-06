<?php

/*
 * Copyright (c) Art Kurbakov <alex110504@gmail.com>
 *
 * For the full copyright and licence information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace App\Service\Exchange\Equities;

use App\Exception\ExchangeException;
use App\Service\Exchange\DailyIterator;
use DateTime;
use FilterIterator;
use ReflectionException;
use Yasumi\Holiday;
use Yasumi\Provider\USA;
use Yasumi\Yasumi;

/**
 * Class TradingCalendar
 * Removes trading holidays specific to NYSE and NASDAQ from date iterator
 * @package App\Service\Exchange\Equities
 */
class TradingCalendar extends FilterIterator
{
    public const TIMEZONE = 'America/New_York';

    protected $holidaysCalculator;

    public function __construct(
        DailyIterator $iterator
    ) {
        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        $date = $this->getInnerIterator()->current();
        try {
            $this->initCalculator((int) $date->format('Y'));
            return $this->holidaysCalculator->isWorkingDay($date);
        } catch (ReflectionException $e) {
            throw new ExchangeException($e->getMessage());
        }
    }

    /**
     * @param integer $year
     * @throws ReflectionException
     */
    private function initCalculator(int $year)
    {
        /** @var USA holidaysCalculator */
        $this->holidaysCalculator = Yasumi::create('USA', $year);

        $this->holidaysCalculator->addHoliday($this->holidaysCalculator->goodFriday($year, self::TIMEZONE, 'en_US'));
        $this->holidaysCalculator->removeHoliday('columbusDay');
        $this->holidaysCalculator->removeHoliday('veteransDay');
        $this->holidaysCalculator->removeHoliday('substituteHoliday:veteransDay');

        $this->holidaysCalculator->addHoliday(new Holiday('HurricaneSandy1', [], new DateTime('2012-10-29')));
        $this->holidaysCalculator->addHoliday(new Holiday('HurricaneSandy2', [], new DateTime('2012-10-30')));
        $this->holidaysCalculator->addHoliday(new Holiday('BushMourning', [], new DateTime('2018-12-05')));
    }
}
