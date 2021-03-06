<?php
/*
 * Copyright (c) Art Kurbakov <alex110504@gmail.com>
 *
 * For the full copyright and licence information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace App\Service\PriceHistory\OHLCV;

use App\Entity\OHLCV\History;
use App\Exception\PriceHistoryException;
use App\Entity\OHLCV\Quote;
use App\Entity\Instrument;
use App\Service\Exchange\Catalog;
use App\Service\Exchange\Equities\TradingCalendar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use League\Csv\Writer;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Scheb\YahooFinanceApi\Exception\ApiException;

/**
 * Class Yahoo
 * Works with OLCV format price histories and quotes.
 * Downloads from Yahoo unadvertised API
 * To configure a different price provider use service configuration file to specify factory and method.
 * @uses \Scheb\YahooFinanceApi\ApiClient
 */
class Yahoo implements \App\Service\PriceHistory\PriceProviderInterface
{
    const PROVIDER_NAME = 'YAHOO';

    const INTERVAL_DAILY = 'P1D';
    const INTERVAL_WEEKLY = 'P1W';
    const INTERVAL_MONTHLY = 'P1M';

    /**
     * Currently supported intervals from the Price Provider
     * These follow interval spec for the \DateInterval class
     */
    public $intervals = [
        self::INTERVAL_DAILY,
        self::INTERVAL_WEEKLY,
        self::INTERVAL_MONTHLY,
    ];

    /**
     * Dedicated adapter which converts its entities into app entities: quotes and OHLCV\History.
     * Price adapter is written to adapt data from Scheb's API provider, into our format.
     * @var App\Service\PriceHistory\PriceAdapter_Scheb
     */
    private $priceAdapter;

    /**
     * @var Doctrine\ORM\EntityManager
     */
    public $em;

    /**
     * @var App\Repository\InstrumentRepository
     */
    private $instrumentRepository;

    /**
     * @var TradingCalendar
     */
    private $tradingCalendar;

    /**
     * @var App\Service\Exchange\Catalog
     */
    private $catalog;

    public function __construct(
        RegistryInterface $registry,
        PriceAdapter_Scheb $priceAdapter,
        TradingCalendar $tradingCalendar,
        Catalog $catalog
    ) {
        $this->em = $registry->getManager();
        $this->priceAdapter = $priceAdapter;
        $this->instrumentRepository = $this->em->getRepository(Instrument::class);
        $this->tradingCalendar = $tradingCalendar;
        $this->catalog = $catalog;
    }

    /**
     * {@inheritDoc}
     * @param $instrument
     * @param $fromDate
     * @param $toDate
     * @param array $options ['interval' => 'P1M|P1W|P1D' ]
     * @return \App\Entity\OHLCV\History[]
     * @throws PriceHistoryException
     * @throws \Scheb\YahooFinanceApi\Exception\ApiException
     */
    public function downloadHistory($instrument, $fromDate, $toDate, $options)
    {
        if ('test' == $_SERVER['APP_ENV'] && isset($_SERVER['TODAY'])) {
            $today = $_SERVER['TODAY'];
        } else {
            $today = date('Y-m-d');
        }

        $exchange = $this->catalog->getExchangeFor($instrument);

        if ($toDate->format('U') > strtotime($today)) {
            $toDate = new \DateTime($today);
        }
        // test for exceptions:
        if ($toDate->format('Y-m-d') == $fromDate->format('Y-m-d')) {
            throw new PriceHistoryException(
                sprintf('$fromDate %s is equal to $toDate %s', $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'))
            );
        }
        if ($toDate->format('U') < $fromDate->format('U')) {
            throw new PriceHistoryException(
                sprintf('$toDate %s is earlier than $fromDate %s', $toDate->format('Y-m-d'), $fromDate->format('Y-m-d'))
            );
        }
        $hours = ($toDate->format('U') - $fromDate->format('U')) / 3600;
        // check if $toDate and $fromDate are on the same weekend, then except if ()
        if ($fromDate->format('w') == 6 && $toDate->format('w') == 0 && $hours <= 48) {
            throw new PriceHistoryException(
                sprintf(
                    '$fromDate %s and $toDate %s are on the same weekend.',
                    $fromDate->format('Y-m-d'),
                    $toDate->format('Y-m-d')
                )
            );
        }
        // check if $toDate or $fromDate is a long weekend (includes a contiguous holiday, then except
        if ($hours <= 72 && ((!$exchange->isTradingDay($fromDate) && $toDate->format('w') == 0) || ($fromDate->format(
                        'w'
                    ) == 6 && !$exchange->isTradingDay($toDate)))) {
            throw new PriceHistoryException(
                sprintf(
                    '$fromDate %s and $toDate %s are on the same long weekend.',
                    $fromDate->format('Y-m-d'),
                    $toDate->format('Y-m-d')
                )
            );
        }

        if (isset($options['interval'])) {
            if (in_array($options['interval'], $this->intervals)) {
                try {
                    $history = $this->priceAdapter->getHistoricalData($instrument, $fromDate, $toDate, $options);
                } catch (ClientException $e) {
                    throw new PriceHistoryException($e->getMessage(), $code = 2);
                } catch (ServerException $e) {
                    throw new PriceHistoryException($e->getMessage(), $code = 3);
                } catch (ApiException $e) {
                    throw new PriceHistoryException($e->getMessage(), $code = 3);
                }
            } else {
                throw new PriceHistoryException(sprintf('Interval %s is not serviced', $options['interval']));
            }
        } else {
            throw new PriceHistoryException(
                sprintf(
                    'Interval for the price history must be explicitly set in $options passed to %s',
                    __METHOD__
                )
            );
        }

        // order history items from oldest date to latest
        $this->sortHistory($history);

        return $history;
    }

    public function addHistory($instrument, $history)
    {
        if (!empty($history)) {
            // delete existing OHLCV History for the given instrument from history start date to current date
            $OHLCVRepository = $this->em->getRepository(History::class);
            // var_dump(get_class($OHLCVRepository)); exit();
            $fromDate = $history[0]->getTimestamp();
            $interval = $history[0]->getTimeinterval();
            $OHLCVRepository->deleteHistory($instrument, $fromDate, null, $interval, self::PROVIDER_NAME);

            // save the given history
            foreach ($history as $record) {
                $this->em->persist($record);
            }

            $this->em->flush();
        }
    }

    public function exportHistory($history, $path, $options = [])
    {
        $csv = Writer::createFromPath($path, 'w');
        $header = ['Date', 'Open', 'High', 'Low', 'Close', 'Volume'];
        $csv->insertOne($header);

        $csv->insertAll(array_map(function($v) {
            return [$v->getTimestamp()->format('Y-m-d'), $v->getOpen(), $v->getHigh(), $v->getLow(), $v->getClose(), $v->getVolume()];
        }, $history));
    }

    /**
     * {@inheritDoc}
     * @param array $options ['interval' => 'P1M|P1W|P1D' ]
     * @throws PriceHistoryException
     */
    public function retrieveHistory($instrument, $fromDate, $toDate, $options)
    {
        if (isset($options['interval']) && !in_array($options['interval'], $this->intervals)) {
            throw new PriceHistoryException(
                'Requested interval `%s` is not in array of serviced intervals.',
                $options['interval']
            );
        } elseif (isset($options['interval'])) {
            $interval = new \DateInterval($options['interval']);
        } else {
            $interval = new \DateInterval('P1D');
        }

        $OHLCVRepository = $this->em->getRepository(History::class);

        return $OHLCVRepository->retrieveHistory($instrument, $interval, $fromDate, $toDate, self::PROVIDER_NAME);
    }

    public function downloadQuote($instrument)
    {
        if ('test' == $_SERVER['APP_ENV'] && isset($_SERVER['TODAY'])) {
            $today = new \DateTime($_SERVER['TODAY']);
        } else {
            $today = new \DateTime();
        }

        $exchange = $this->catalog->getExchangeFor($instrument);

        if ($exchange->isOpen($today)) {
            return $this->priceAdapter->getQuote($instrument);
        } else {
            return null;
        }
    }

    public function saveQuote($instrument, $quote)
    {
        if ($oldQuote = $instrument->getOHLCVQuote()) {
            $this->em->remove($oldQuote);
            $this->em->flush();
        }

        $instrument->setOHLCVQuote($quote);

        $this->em->persist($instrument);
        $this->em->flush();
    }

    public function removeQuote($instrument)
    {
        if ($oldQuote = $instrument->getOHLCVQuote()) {
            $this->em->remove($oldQuote);
            $this->em->flush();
        }

        $instrument->unsetOHLCVQuote();

        $this->em->persist($instrument);
        $this->em->flush();
    }

    /**
     * @param $quote App\Entity\OHLCV\Quote
     * @param $history App\Entity\OHLCV\History[]
     * {@inheritDoc}
     * @throws PriceHistoryException
     */
    public function addQuoteToHistory($quote, $history = [])
    {
        // handle test environment
        if ('test' == $_SERVER['APP_ENV'] && isset($_SERVER['TODAY'])) {
            $today = new \DateTime($_SERVER['TODAY']);
        } else {
            $today = new \DateTime();
        }

        if ($today->format('Ymd') != $quote->getTimestamp()->format('Ymd')) {
            return null;
        }

        $instrument = $quote->getInstrument();
        $exchange = $this->catalog->getExchangeFor($instrument);
        $quoteInterval = $quote->getTimeinterval();
        $prevT = $exchange->calcPreviousTradingDay($quote->getTimestamp());

        if ($exchange->isOpen($today)) {
            if (empty($history)) {
                // is there history in storage?
                $repository = $this->em->getRepository(History::class);
                $history = $repository->retrieveHistory(
                    $instrument,
                    $quoteInterval,
                    $prevT,
                    $today,
                    self::PROVIDER_NAME
                );
                if (!empty($history)) {
                    // check if quote date is the same as latest history date, then just overwrite and save in db, return true
                    $lastElement = array_pop($history);
                    if ($lastElement->getTimestamp()->format('Ymd') == $quote->getTimestamp()->format('Ymd')) {
                        $newElement = $this->castQuoteToHistory($quote);
                        $this->em->remove($lastElement);
                        $this->em->persist($newElement);
                        $this->em->flush();

                        return true;
                    } // check if latest history date is prevT (previous trading period for weekly, monthly, and yearly) from quote date, then add quote on top of history, return true
                    else {
                        switch ($quoteInterval) {
                            case 1 == $quoteInterval->d :
                                if ($lastElement->getTimestamp()->format('Ymd') == $prevT->format('Ymd')) {
                                    $newElement = $this->castQuoteToHistory($quote);
                                    $this->em->persist($newElement);
                                    $this->em->flush();

                                    return true;
                                }
                            case 7 == $quoteInterval->d :

                                break;
                            case 1 == $quoteInterval->m :

                                break;
                            case 1 == $quoteInterval->y :
                        }

                        // quote must be inside of history
                        return false;
                    }
                } // decide if need to return false (gap) or null (no op). Check if history exists for an instrument
                else {
                    $history = $repository->retrieveHistory(
                        $instrument,
                        $quoteInterval,
                        null,
                        $today,
                        self::PROVIDER_NAME
                    );
                    if (empty($history)) {
                        return null;
                    } else {
                        return false;
                    }
                }
            } else {
                end($history);
                $lastElement = current($history);
                $indexOfLastElement = key($history);

                // check if instruments match
                if ($lastElement->getInstrument()->getSymbol() != $quote->getInstrument()->getSymbol()) {
                    throw new PriceHistoryException('Instruments in history and quote don\'t match');
                }
                // check if intervals match
                $historyInterval = $lastElement->getTimeinterval();

                if ($historyInterval->format('ymdhis') != $quoteInterval->format('ymdhis')) {
                    throw new PriceHistoryException('Time intervals in history and quote don\'t match');
                }

                // check if quote date is the same as latest history date, then replace history object with new one,
                // which has new price, return $history modified
                if ($lastElement->getTimestamp()->format('Ymd') == $quote->getTimestamp()->format('Ymd')) {
                    $lastElement = $this->castQuoteToHistory($quote);
                    $history[$indexOfLastElement] = $lastElement;
                    reset($history); // resets array pointer to first element

                    return $history;
                } // check if latest history date is prevT (previous trading period for weekly, monthly, and yearly) from quote date, then add quote on top of history, return $history modified
                else {
                    // depending on time interval we must handle the prevT differently
                    switch ($quoteInterval) {
                        case 1 == $quoteInterval->d :
                            if ($lastElement->getTimestamp()->format('Ymd') == $prevT->format('Ymd')) {
                                $history[] = $this->castQuoteToHistory($quote);

                                reset($history); // resets array pointer to first element

                                return $history;
                            }
                        case 7 == $quoteInterval->d :

                            break;
                        case 1 == $quoteInterval->m :

                            break;
                        case 1 == $quoteInterval->y :
                    }

                    return false;
                }
            }
        }

        return null;
    }

    public function retrieveQuote($instrument)
    {
        return $instrument->getOHLCVQuote();
    }

    public function downloadClosingPrice($instrument)
    {
        if ('test' == $_SERVER['APP_ENV'] && isset($_SERVER['TODAY'])) {
            $today = new \DateTime($_SERVER['TODAY']);
        } else {
            $today = new \DateTime();
        }

        $exchange = $this->catalog->getExchangeFor($instrument);

        if ($exchange->isOpen($today)) {
            return null;
        }

        if ($exchange->isTradingDay($today)) {
            // Price provider API is always tied to real dates and times. Figure out if need to use getQuote or downloadHistory
            // Get quote first, then see if date on it matches today
            $quote = $this->priceAdapter->getQuote($instrument);

            if ($quote->getTimestamp()->format('Ymd') == $today->format('Ymd')) {
                return $this->castQuoteToHistory($quote);
            }
        }

        $prevT = $exchange->calcPreviousTradingDay($today);
        $gapHistory = $this->downloadHistory($instrument, $prevT, $today, ['interval' => 'P1D']);
        $lastElement = array_pop($gapHistory);

        if ($lastElement) {
            return $lastElement;
        }
    }

    /**
     * {@inheritDoc}
     * @param Instrument $instrument
     * @return App\Entity\OHLCV\History | null
     */
    public function retrieveClosingPrice($instrument)
    {
        /** @var App\Repository\OHLCVHistoryRepository $repository */
        $repository = $this->em->getRepository(History::class);

        $closingPrice = $repository->findOneBy(
            ['instrument' => $instrument, 'provider' => self::PROVIDER_NAME],
            ['timestamp' => 'desc']
        );

        return $closingPrice;
    }

    /**
     * @param App\Entity\OHLCV\History $closingPrice
     * @param array $history | null
     * @return array $history | false | null
     * @throws PriceHistoryException
     */
    public function addClosingPriceToHistory($closingPrice, $history = [])
    {
        $instrument = $closingPrice->getInstrument();
        $exchange = $this->catalog->getExchangeFor($instrument);
        $closingPriceInterval = $closingPrice->getTimeinterval();
        $prevT = $exchange->calcPreviousTradingDay($closingPrice->getTimestamp());

        if (empty($history)) {
            if ($storedClosingPrice = $this->retrieveClosingPrice($instrument)) {
                if ($storedClosingPrice->getTimestamp()->format('Ymd') == $closingPrice->getTimestamp()->format(
                        'Ymd'
                    )) {
                    $this->em->remove($storedClosingPrice);
                    $this->em->persist($closingPrice);
                    $this->em->flush();

                    return true;
                }
                if ($storedClosingPrice->getTimestamp()->format('Ymd') == $prevT->format('Ymd')) {
                    $this->em->persist($closingPrice);
                    $this->em->flush();

                    return true;
                }

                // either gap or inside history
                return false;
            } else {
                $this->em->persist($closingPrice);
                $this->em->flush();

                return true;
            }

        } else {
            end($history);
            $lastElement = current($history);
            $indexOfLastElement = key($history);
            $historyInterval = $lastElement->getTimeinterval();

            // check if instruments match
            if ($lastElement->getInstrument()->getSymbol() != $closingPrice->getInstrument()->getSymbol()) {
                throw new PriceHistoryException('Instruments in History and Closing Price don\'t match');
            }
            // check if intervals match
            if ($historyInterval->format('ymdhis') != $closingPriceInterval->format('ymdhis')) {
                throw new PriceHistoryException('Time intervals in History and Closing Price don\'t match');
            }

            // check if quote date is the same as latest history date, then just overwrite, return $history modified
            if ($lastElement->getTimestamp()->format('Ymd') == $closingPrice->getTimestamp()->format('Ymd')) {

                $history[$indexOfLastElement] = $closingPrice;
                reset($history); // resets array pointer to first element

                return $history;
            }

            // check for no gap:
            if ($lastElement->getTimestamp()->format('Ymd') == $prevT->format('Ymd')) {
                $history[] = $closingPrice;

                reset($history);

                return $history;
            }

            // either gap or is inside history:
            return false;
        }

        return null;
    }

    /**
     * Retrieve several quotes from Price Provider in one request
     * Does not check if market is open or closed.
     * @param App\Entity\Instrument[] $list
     * @return \App\Service\PriceHistory\App\Entity\OHLCV\Quote[] | null
     */
    public function getQuotes($list)
    {
        return $this->priceAdapter->getQuotes($list);
    }

    /**
     * Orders history elements from oldest date to the latest
     * @param App\Entity\OHLCV\History[] $history
     */
    private function sortHistory(&$history)
    {
        uasort(
            $history,
            function ($a, $b) {
                if ($a->getTimestamp()->format('U') == $b->getTimestamp()->format('U')) {
                    return 0;
                }

                return ($a->getTimestamp()->format('U') < $b->getTimestamp()->format('U')) ? -1 : 1;
            }
        );
    }

    /**
     * Converts DateInterval into a given unit. For now supports only seconds (s)
     * @param \DateInterval $interval
     * @param string $unit
     * @return integer $result
     */
    private function convertInterval($interval, $unit)
    {
        switch ($unit) {
            case 's':
                $result = 86400 * ($interval->y * 365 + $interval->m * 28.5 + $interval->d) + $interval->h * 3600 + $interval->m * 60 + $interval->s;
                break;
        }

        return $result;
    }

    /**
     * Converts OHLCV\Quote object to OHLCV\History Object
     * @param Quote $quote
     * @return History $element
     */
    public function castQuoteToHistory(Quote $quote)
    {
        $element = new History();
        $element->setInstrument($quote->getInstrument());
        $element->setProvider(self::PROVIDER_NAME);
        $element->setTimeinterval($quote->getTimeinterval());
        $element->setTimestamp($quote->getTimestamp());
        $element->setOpen($quote->getOpen());
        $element->setHigh($quote->getHigh());
        $element->setLow($quote->getLow());
        $element->setClose($quote->getClose());
        $element->setVolume($quote->getVolume());

        return $element;
    }
}
