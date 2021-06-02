<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection */

namespace App\Studies\MGWebGroup\MarketSurvey;

use App\Entity\Expression;
use App\Entity\Instrument;
use App\Entity\OHLCV\History;
use App\Entity\Study\ArrayAttribute;
use App\Entity\Study\FloatAttribute;
use App\Entity\Study\Study;
use App\Entity\Watchlist;
use App\Exception\ChartException;
use App\Exception\ExpressionException;
use App\Exception\PriceHistoryException;
use App\Repository\StudyArrayAttributeRepository;
use App\Repository\StudyFloatAttributeRepository;
use App\Repository\WatchlistRepository;
use App\Service\Charting\OHLCV\ChartFactory;
use App\Service\Charting\StyleInterface;
use App\Service\Exchange\Equities\TradingCalendar;
use App\Service\Exchange\MonthlyIterator;
use App\Service\Exchange\WeeklyIterator;
use App\Service\ExpressionHandler\OHLCV\Calculator;
use App\Service\Scanner\OHLCV\Scanner;
use DateInterval;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Exception;
use LimitIterator;
use Symfony\Bridge\Doctrine\RegistryInterface;
use App\Service\Scanner\ScannerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\Common\Collections\Criteria;
use App\Studies\MGWebGroup\MarketSurvey\Exception\StudyException;
use MathPHP\Statistics\Descriptive;
use Doctrine\ORM\PersistentCollection;

/**
 * Implements all calculations necessary for the Market Survey. Based on procedures of October 2018.
 * The Market Survey study has 6 areas:
 *   - Market Breadth table (is a basis for Inside Bar watch lists, Market Score and Actionable Symbols list)
 *   - Inside Bar Breakout/Breakdown table
 *   - Actionable Symbols list
 *   - Actionable Symbols Breakout/Breakdown table
 *   - Market Score statistic
 *   - Sectors table
 *   - Y-Universe scoring table
 *
 */
class StudyBuilder
{
    public const INSIDE_BAR_DAY = 'Ins D';
    public const INSIDE_BAR_WK = 'Ins Wk';
    public const INSIDE_BAR_MO = 'Ins Mo';
    public const INS_D_AND_UP = 'Ins D & Up';
    public const D_HAMMER = 'D Hammer';
    public const D_HAMMER_AND_UP = 'D Hammer & Up';
    public const D_BULLISH_ENG = 'D Bullish Eng';
    public const INS_D_AND_DWN = 'Ins D & Dwn';
    public const D_SHTNG_STAR = 'D Shtng Star';
    public const D_SHTNG_STAR_AND_DWN = 'D Shtng Star & Down';
    public const D_BEARISH_ENG = 'D Bearish Eng';
    public const D_BO = 'D BO';
    public const D_BD = 'D BD';
    public const POS_ON_D = 'Pos on D';
    public const NEG_ON_D = 'Neg on D';
    public const WK_BO = 'Wk BO';
    public const WK_BD = 'Wk BD';
    public const POS_ON_WK = 'Pos on Wk';
    public const NEG_ON_WK = 'Neg on Wk';
    public const MO_BO = 'Mo BO';
    public const MO_BD = 'Mo BD';
    public const POS_ON_MO = 'Pos on Mo';
    public const NEG_ON_MO = 'Neg on Mo';

    public const P_DAILY = 'P';
    public const DELTA_P_PRCNT = 'delta P prcnt';

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Scanner;
     */
    private $scanner;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var Calculator
     */
    private $calculator;

    /**
     * @var Study
     */
    private $study;

    /**
     * @var TradingCalendar
     */
    private $tradeDayIterator;

    /**
     * @var WeeklyIterator
     */
    private $weeklyIterator;

    /**
     * @var MonthlyIterator
     */
    private $monthlyIterator;

    /**
     * @var Collection
     */
    private $excludedInstruments;


    public function __construct(
        RegistryInterface $registry,
        ScannerInterface $scanner,
        ContainerInterface $container,
        Calculator $calculator,
        TradingCalendar $tradingCalendar,
        WeeklyIterator $weeklyIterator,
        MonthlyIterator $monthlyIterator
    ) {
        $this->em = $registry->getManager();
        $this->scanner = $scanner;
        $this->container = $container;
        $this->calculator = $calculator;
        $this->tradeDayIterator = $tradingCalendar;
        $this->weeklyIterator = $weeklyIterator;
        $this->monthlyIterator = $monthlyIterator;
    }

    /**
     * Will override existing study if exists
     */
    public function initStudy(Datetime $date, string $name, string $version = null): StudyBuilder
    {
        $this->study = new Study();
        $this->study->setDate($date);
        $this->study->setName($name);
        $this->study->setVersion($version);
        $this->excludedInstruments = new ArrayCollection();

        return $this;
    }

    public function getStudy(): Study
    {
        return $this->study;
    }

    /**
     * Sets a list of instrument that are to be excluded from calculations in the following methods:
     *   calculateMarketBreadth
     *   figureInsideBarBOBD
     *   figureASBOBD
     *   buildActionableSymbolsWatchlist
     * @param Collection $excluded
     */
    public function setExcluded(Collection $excluded)
    {
        $this->excludedInstruments = $excluded;
    }

    /**
     * Creates market-breadth array = [
     *   'Ins D & Up' => App\Entity\Instrument[]
     *   'Ins Wk & Up' => App\Entity\Instrument[]
     *   ...
     * ]
     * and saves it as 'market-breadth' array attribute in $this->study. If no instruments met a criteria, empty
     * instrument array will be present. For example, 'Ins D' => []
     *
     * Calculates market score according to the metric stored in mgweb.yaml parameters, and saves it as 'market-score'
     * float attribute in $this->study.
     *
     * Creates Inside Bar Daily, Weekly, Monthly watch lists. Also creates bullish and bearish watch lists, which are
     * later used in Inside Bar Breakouts/Breakdowns analysis as well as to build Actionable Symbols lists in
     * other functions of the StudyBuilder. These watch lists are added to $this->study into its $watchlists property.
     *
     * @param Watchlist $watchlist must have expressions associated with it for daily breakouts, breakdowns, and volume:
     *   D BO:D BD:V
     * These expressions are used in figuring out of Actionable Symbols list
     * @return StudyBuilder
     */
    public function calculateMarketBreadth(Watchlist $watchlist): StudyBuilder
    {
        $metric = $this->container->getParameter('market-score');
        $expressions = [];
        foreach ($metric as $exprName => $score) {
            $expression = $this->em->getRepository(Expression::class)->findOneBy(['name' => $exprName]);
            $expressions[] = $expression;
        }

        foreach ($this->excludedInstruments as $instrument) {
            $watchlist->getInstruments()->removeElement($instrument);
        }

        $survey = $this->doScan($this->study->getDate(), $watchlist, $expressions);
        StudyArrayAttributeRepository::createArrayAttr($this->study, 'market-breadth', $survey);

        $score = $this->calculateScore($survey, $metric);
        StudyFloatAttributeRepository::createFloatAttr($this->study, 'market-score', $score);

        $insideBars = [self::INSIDE_BAR_DAY, self::INSIDE_BAR_WK, self::INSIDE_BAR_MO];
        foreach ($insideBars as $insideBar) {
            if (!empty($survey[$insideBar])) {
                $newWatchlist[$insideBar] = WatchlistRepository::createWatchlist(
                    $insideBar,
                    null,
                    $watchlist->getExpressions(),
                    $survey[$insideBar]
                );
                $this->study->addWatchlist($newWatchlist[$insideBar]);
            }
        }

        $bullish = [self::INS_D_AND_UP, self::D_HAMMER, self::D_HAMMER_AND_UP, self::D_BULLISH_ENG];
        foreach ($bullish as $signal) {
            if (!empty($survey[$signal])) {
                $newWatchlist[$signal] = WatchlistRepository::createWatchlist(
                    $signal,
                    null,
                    $watchlist->getExpressions(),
                    $survey[$signal]
                );
                $this->study->addWatchlist($newWatchlist[$signal]);
            }
        }

        $bearish = [self::INS_D_AND_DWN, self::D_SHTNG_STAR, self::D_SHTNG_STAR_AND_DWN, self::D_BEARISH_ENG];
        foreach ($bearish as $signal) {
            if (!empty($survey[$signal])) {
                $newWatchlist[$signal] = WatchlistRepository::createWatchlist(
                    $signal,
                    null,
                    $watchlist->getExpressions(),
                    $survey[$signal]
                );
                $this->study->addWatchlist($newWatchlist[$signal]);
            }
        }

        return $this;
    }

    /**
     * Takes 'market-score' attribute in the $pastStudy, figures delta from the current score in current study and
     * saves this delta as 'score-delta' float attribute
     * @param Study $pastStudy past study which has its Market Score stored in float attribute
     * 'market-score'
     * @return StudyBuilder
     */
    public function calculateScoreDelta($pastStudy = null): StudyBuilder
    {
        $getScore = new Criteria(Criteria::expr()->eq('attribute', 'market-score'));
        if (!$pastStudy) {
            $pastScore = 0;
        } else {
            $pastScore = $pastStudy->getFloatAttributes()->matching($getScore)->first()->getValue();
        }
        $score = $this->study->getFloatAttributes()->matching($getScore)->first()->getValue();

        $scoreDelta = $score - $pastScore;
        StudyFloatAttributeRepository::createFloatAttr($this->study, 'score-delta', $scoreDelta);

        return $this;
    }

    /**
     * @param $survey = [ string exprName => App\Entity\Instrument[]]
     * @param $metric = [string exprName => float value]
     * @return float|int
     */
    private function calculateScore($survey, $metric)
    {
        $score = $survey;
        array_walk($score, function (&$v, $k, $metric) {
            $v = count($v) * $metric[$k];
        }, $metric);
        return array_sum($score);
    }

    /**
     * Takes a Watchlist entity and runs scans for each expression in $expressions returning summary of
     * instruments matching each.
     * @param DateTime $date Date for which to run the scan
     * @param Watchlist $watchlist
     * @param array $expressions App\Entity\Expression[]
     * @return array $survey = [ string exprName => App\Entity\Instrument[], ...]
     */
    private function doScan(DateTime $date, Watchlist $watchlist, array $expressions): array
    {
        $survey = [];
        foreach ($expressions as $expression) {
            $survey[$expression->getName()] = $this->scanner->scan(
                $watchlist->getInstruments(),
                $expression->getFormula(),
                $expression->getCriteria(),
                $expression->getTimeinterval(),
                $date
            );
        }

        return $survey;
    }

    /**
     * Given a past study, scans its Inside Bar watch lists for Breakouts/Breakdowns (formula
     * quartets) for the $effectiveDate. Saves results as array attributes 'bobd-daily', 'bobd-weekly',
     * 'bobd-monthly' in $this->study.
     *
     * @param Study $study past study which has Inside Bar watch lists.
     * @param DateTime $effectiveDate date for which to run expressions on Inside Bar watch lists
     * @return StudyBuilder
     * @throws StudyException
     */
    public function figureInsideBarBOBD(Study $study, DateTime $effectiveDate): StudyBuilder
    {
        if ($study->getWatchlists() instanceof PersistentCollection) {
            $study->getWatchlists()->initialize();
        }

        $comparison = Criteria::expr()->in('name', [self::INSIDE_BAR_DAY, self::INSIDE_BAR_WK, self::INSIDE_BAR_MO]);
        $insideBarWatchlistsCriterion = new Criteria($comparison);
        $insideBarWatchlists = $study->getWatchlists()->matching($insideBarWatchlistsCriterion);
        foreach ($insideBarWatchlists as $insideBarWatchlist) {
            $exprName = $insideBarWatchlist->getName();
            switch ($exprName) {
                case self::INSIDE_BAR_DAY:
                    $exprList = [self::D_BO, self::D_BD, self::POS_ON_D, self::NEG_ON_D];
                    $attribute = 'bobd-daily';
                    break;
                case self::INSIDE_BAR_WK:
                    $exprList = [self::WK_BO, self::WK_BD, self::POS_ON_WK, self::NEG_ON_WK];
                    $attribute = 'bobd-weekly';
                    break;
                case self::INSIDE_BAR_MO:
                    $exprList = [self::MO_BO, self::MO_BD, self::POS_ON_MO, self::NEG_ON_MO];
                    $attribute = 'bobd-monthly';
                    break;
                default:
                    $exprList = [];
                    $attribute = null;
            }

            foreach ($this->excludedInstruments as $instrument) {
                $insideBarWatchlist->getInstruments()->removeElement($instrument);
            }

            $bobdTable = $this->makeSurvey($effectiveDate, $insideBarWatchlist, $exprList);

            StudyArrayAttributeRepository::createArrayAttr($this->study, $attribute, $bobdTable);
        }

        return $this;
    }

    /**
     * Given the study, scans its Actionable Symbols (AS) watch list for Breakouts/Breakdowns (formula
     * quartets) for the $effectiveDate. Saves results as array attribute 'as-bobd' in $this->study.
     * @param Study $study past study which has Actionable Symbols watch list.
     * @param DateTime $effectiveDate date for which to run expressions on AS watch list
     * @return StudyBuilder
     * @throws StudyException
     */
    public function figureASBOBD(Study $study, DateTime $effectiveDate): StudyBuilder
    {
        if ($study->getWatchlists() instanceof PersistentCollection) {
            $study->getWatchlists()->initialize();
        }

        $getASWatchlist = new Criteria(Criteria::expr()->eq('name', 'AS'));
        $ASWatchlist = $study->getWatchlists()->matching($getASWatchlist)->first();

        $exprList = [
            self::D_BO, self::D_BD, self::POS_ON_D, self::NEG_ON_D,
            self::WK_BO, self::WK_BD, self::POS_ON_WK, self::NEG_ON_WK,
            self::MO_BO, self::MO_BD, self::POS_ON_MO, self::NEG_ON_MO
        ];
        $attribute = 'as-bobd';

        foreach ($this->excludedInstruments as $instrument) {
            $ASWatchlist->getInstruments()->removeElement($instrument);
        }

        $bobdTable = $this->makeSurvey($effectiveDate, $ASWatchlist, $exprList);

        StudyArrayAttributeRepository::createArrayAttr($this->study, $attribute, $bobdTable);

        return $this;
    }

    /**
     * Performs watch list scan using list of expression names as strings.
     * @param DateTime $date
     * @param Watchlist $watchlist
     * @param array $exprList String[]
     * @return array $bobdTable = [
     *      'survey' => [
     *          <exprName1> => App\Entity\Instrument[],
     *          <exprName2> => App\Entity/Instrument[], ...
     *      ],
     *      'count' => integer
     *   ]
     * @throws StudyException
     */
    private function makeSurvey(DateTime $date, Watchlist $watchlist, array $exprList): array
    {
        $bobdTable = [];

        try {
            $expressions = $this->em->getRepository(Expression::class)->findExpressions($exprList);
        } catch (ExpressionException $e) {
            throw new StudyException($e->getMessage());
        }

        $bobdTable['survey'] = $this->doScan($date, $watchlist, $expressions);
        $bobdTable['count'] = $watchlist->getInstruments()->count();

        return $bobdTable;
    }

    /**
     * Takes specific watch lists already attached to the study and selects top 10 instruments from some lists by price
     *   and from other lists by price and volume to formulate the Actionable Symbols ('AS') watch list. This AS watch
     *   list is attached to the study.
     * @return StudyBuilder
     * @throws StudyException
     */
    public function buildActionableSymbolsWatchlist(): StudyBuilder
    {
        $watchlistsOfInterest = [
          self::INSIDE_BAR_DAY,
          self::INS_D_AND_UP, self::D_HAMMER, self::D_HAMMER_AND_UP, self::D_BULLISH_ENG,
          self::INS_D_AND_DWN, self::D_SHTNG_STAR, self::D_SHTNG_STAR_AND_DWN, self::D_BEARISH_ENG
          ];

        $watchlistsOfInterestCriterion = new Criteria(Criteria::expr()->in('name', $watchlistsOfInterest));
        $actionableInstrumentsArray = [];

        try {
            $expressions = $this->em->getRepository(Expression::class)
              ->findExpressions([self::P_DAILY, self::DELTA_P_PRCNT]);
        } catch (ExpressionException $e) {
            throw new StudyException($e->getMessage());
        }

        foreach ($this->study->getWatchlists()->matching($watchlistsOfInterestCriterion) as $watchlist) {
            foreach ($this->excludedInstruments as $instrument) {
                $watchlist->getInstruments()->removeElement($instrument);
            }
            switch ($watchlist->getName()) {
                case self::INSIDE_BAR_DAY:
                case self::D_BULLISH_ENG:
                case self::D_BEARISH_ENG:
                    $watchlist->update($this->calculator, $this->study->getDate())->sortValuesBy(...['V', SORT_DESC]);
                    break;
                case self::INS_D_AND_UP:
                case self::D_HAMMER:
                case self::D_HAMMER_AND_UP:
                    $watchlist->update(
                        $this->calculator,
                        $this->study->getDate()
                    )->sortValuesBy(...['Pos on D', SORT_DESC, 'V', SORT_DESC]);
                    break;
                case self::INS_D_AND_DWN:
                case self::D_SHTNG_STAR:
                case self::D_SHTNG_STAR_AND_DWN:
                    $watchlist->update(
                        $this->calculator,
                        $this->study->getDate()
                    )->sortValuesBy(...['Neg on D', SORT_ASC, 'V', SORT_DESC]);
                    break;
                default:
            }
            $top10 = array_slice($watchlist->getCalculatedFormulas(), 0, 10);
            foreach ($top10 as $symbol => $values) {
                $actionableInstrumentsArray[] = $this->em->getRepository(Instrument::class)->findOneBy(['symbol' =>
                  $symbol]);
            }
        }
        $actionableSymbolsWatchlist = WatchlistRepository::createWatchlist(
            'AS',
            null,
            $expressions,
            $actionableInstrumentsArray
        );
        $this->study->addWatchlist($actionableSymbolsWatchlist);

        return $this;
    }

    /**
     * Score table contains current score with historical market scores, score deltas, prices for SPY and how many
     *   standard deviations from average the score and the score deltas are for each day. These numbers are used to
     *   mark significant levels for SPY, called the Levels Map. In order to calculate the table correctly, current
     *   study must already have attributes 'market-score' and 'score-delta' calculated. Also, studies for the $daysBack
     *   must already be saved in database with the same attributes available.
     * Function attaches new array attribute 'score-table-rolling' to the $study.
     * @param integer $daysBack
     * @return StudyBuilder
     * @throws StudyException
     * @throws PriceHistoryException
     */
    public function buildMarketScoreTableForRollingPeriod(int $daysBack): StudyBuilder
    {
        /** @var Instrument | null $SPY */
        $SPY = $this->em->getRepository(Instrument::class)->findOneBy(['symbol' => 'SPY']);
        if (!$SPY) {
            throw new StudyException('Could not find instrument for `SPY`');
        }

        $interval = History::getOHLCVInterval(History::INTERVAL_DAILY);

        $scoreTableRolling = ['table' => [], 'summary' => []];

        $date = clone $this->study->getDate();

        $studyParams = $this->getScoreTableParams($this->study, $SPY, $interval);
        $studyParams['date'] = $date;
        $scoreTableRolling['table'][] = $studyParams;

        $this->tradeDayIterator->getInnerIterator()->setStartDate($date)->setDirection(-1);
        $this->tradeDayIterator->getInnerIterator()->rewind();
        while ($daysBack > 0) {
            $this->tradeDayIterator->next();
            $date = $this->tradeDayIterator->current();

            $study = $this->em->getRepository(Study::class)->findOneBy(['date' => $date]);
            if (!$study) {
                throw new StudyException(sprintf('Could not find study for date %s', $date->format('Y-m-d H:i:s')));
            }

            $studyParams = $this->getScoreTableParams($study, $SPY, $interval);
            $studyParams['date'] = clone $date;
            $scoreTableRolling['table'][] = $studyParams;

            $daysBack--;
        }

        if (count($scoreTableRolling['table']) > 0) {
            $scoreTableRolling = $this->addScoreTableSummary($scoreTableRolling);

            StudyArrayAttributeRepository::createArrayAttr($this->study, 'score-table-rolling', $scoreTableRolling);
        }

        return $this;
    }

    private function addScoreTableSummary($scoreTable)
    {
        $count = count($scoreTable['table']);
        $scoreColumn = array_column($scoreTable['table'], 'score');
        $scoreTable['summary']['score-avg'] = array_sum($scoreColumn) / $count;
        $scoreTable['summary']['score-max'] = max($scoreColumn);
        $scoreTable['summary']['score-min'] = min($scoreColumn);
        $deltaColumn = array_column($scoreTable['table'], 'delta');
        $scoreTable['summary']['delta-avg'] = array_sum($deltaColumn) / $count;
        $scoreTable['summary']['delta-max'] = max($deltaColumn);
        $scoreTable['summary']['delta-min'] = min($deltaColumn);
        $PColumn = array_column($scoreTable['table'], 'P');
        $scoreTable['summary']['P-avg'] = array_sum($PColumn) / $count;

        $scoreTable['summary']['score-std_div'] = Descriptive::sd($scoreColumn);
        $scoreTable['summary']['delta-std_div'] = Descriptive::sd($deltaColumn);

        $scoreTable['summary']['score-days_pos'] = array_reduce($scoreColumn, function ($carry, $item) {
            if ($item > 0) {
                $carry++;
            } return $carry;
        }, 0);
        $scoreTable['summary']['score-days_neg'] = array_reduce($scoreColumn, function ($carry, $item) {
            if ($item < 0) {
                $carry++;
            } return $carry;
        }, 0);

        $scoreAvg = $scoreTable['summary']['score-avg'];
        $scoreStdDev = $scoreTable['summary']['score-std_div'];
        $deltaAvg = $scoreTable['summary']['delta-avg'];
        $deltaStdDev = $scoreTable['summary']['delta-std_div'];
        $updatedTable =  array_map(
            function ($record) use ($scoreAvg, $scoreStdDev, $deltaAvg, $deltaStdDev) {
                $record['score-std_div_qty'] = $scoreStdDev > 0 ? ($record['score'] - $scoreAvg) / $scoreStdDev : 0;
                $record['delta-std_div_qty'] = $deltaStdDev > 0 ? ($record['delta'] - $deltaAvg) / $deltaStdDev : 0;
                return $record;
            },
            $scoreTable['table']
        );

        $scoreTable['table'] = $updatedTable;

        return $scoreTable;
    }

    /**
     * @param Study $study
     * @param Instrument $instrument
     * @param DateInterval $interval
     * @return array
     * @throws StudyException
     */
    private function getScoreTableParams(Study $study, Instrument $instrument, DateInterval $interval): array
    {
        $date = $study->getDate();

        $getScore = new Criteria(Criteria::expr()->eq('attribute', 'market-score'));
        /** @var FloatAttribute | false  $scoreFloatAttr */
        $scoreFloatAttr = $study->getFloatAttributes()->matching($getScore)->first();
        if ($scoreFloatAttr) {
            $score = $scoreFloatAttr->getValue();
        } else {
            throw new StudyException(sprintf('Study with id=%d is missing its current score.', $study->getId()));
        }

        $getScoreDelta = new Criteria(Criteria::expr()->eq('attribute', 'score-delta'));
        $scoreDeltaFloatAttr = $study->getFloatAttributes()->matching($getScoreDelta)->first();
        if ($scoreDeltaFloatAttr) {
            $scoreDelta = $scoreDeltaFloatAttr->getValue();
        } else {
            throw new StudyException('Study in the StudyBuilder is missing current score delta');
        }

        /** @var History $h */
        $h = $this->em->getRepository(History::class)
          ->findOneBy(['instrument' => $instrument, 'timestamp' => $date, 'timeinterval' => $interval]);
        if (!$h) {
            throw new StudyException(sprintf(
                'Price history for instrument `%s` and date `%s` could not be found',
                $instrument->getSymbol(),
                $date->format('Y-m-d H:i:s')
            ));
        }

        return ['score' => $score, 'delta' => $scoreDelta, 'P' => $h->getClose()];
    }

    /**
     * Same as method buildMarketScoreTableForRollingPeriod, but starts from beginning of month. Saves results in
     * new array attribute 'score-table-mtd'.
     * @return StudyBuilder
     * @throws PriceHistoryException
     * @throws StudyException
     */
    public function buildMarketScoreTableForMTD(): StudyBuilder
    {
        /** @var Instrument | null $SPY */
        $SPY = $this->em->getRepository(Instrument::class)->findOneBy(['symbol' => 'SPY']);
        if (!$SPY) {
            throw new StudyException('Could not find instrument for `SPY`');
        }

        $interval = History::getOHLCVInterval(History::INTERVAL_DAILY);

        $scoreTableMTD = ['table' => [], 'summary' => []];

        $date = $this->study->getDate();

        $studyParams = $this->getScoreTableParams($this->study, $SPY, $interval);
        $studyParams['date'] = $date;
        $scoreTableMTD['table'][] = $studyParams;

        $this->tradeDayIterator->getInnerIterator()->setStartDate($date)->setDirection(-1);
        $this->tradeDayIterator->getInnerIterator()->rewind();
        $this->tradeDayIterator->next();
        while ($this->tradeDayIterator->current()->format('d') < $date->format('d')) {
            $date = clone $this->tradeDayIterator->current();

            $study = $this->em->getRepository(Study::class)->findOneBy(['date' => $date]);
            if (!$study) {
                throw new StudyException(sprintf('Could not find study for date %s', $date->format('Y-m-d H:i:s')));
            }

            $studyParams = $this->getScoreTableParams($study, $SPY, $interval);
            $studyParams['date'] = $date;
            $scoreTableMTD['table'][] = $studyParams;

            $this->tradeDayIterator->next();
        }

        if (count($scoreTableMTD['table']) > 0) {
            $scoreTableMTD = $this->addScoreTableSummary($scoreTableMTD);

            StudyArrayAttributeRepository::createArrayAttr($this->study, 'score-table-mtd', $scoreTableMTD);
        }

        return $this;
    }

    /**
     * Takes sector watch list and uses prices for sectors to figure various parameters.
     * The sector watch list must have P:delta P prcnt:delta P(5) prcnt expressions associated with it. File
     *   sectors.csv already comes with the sectors and formulas in it. Please refer to the study README.md file on
     *   how how to import it.
     * Saves new array attribute for the study titled 'sector-table'.
     * Will throw StudyException if 4 past Studies are not stored in database.
     * @param Watchlist $watchlist Sector Watchlist
     * @param DateTime $date
     * @return StudyBuilder
     * @throws StudyException
     */
    public function buildSectorTable(Watchlist $watchlist, DateTime $date): StudyBuilder
    {
        $watchlist->update($this->calculator, $date);

        $watchlist->sortValuesBy('delta P(5) prcnt', SORT_DESC);

        try {
            $this->weeklyIterator->getInnerIterator()->getInnerIterator()->setStartDate($date)->setDirection(-1);
            $this->weeklyIterator->rewind();
            $beginningOfWeek = clone $this->weeklyIterator->current();

            $this->monthlyIterator->getInnerIterator()->getInnerIterator()->setStartDate($date);
            $this->monthlyIterator->rewind();
            $beginningOfMonth = clone $this->monthlyIterator->current();

            $aDate = clone $beginningOfMonth;
            while (($aDate->format('n') - 1) % 3 > 0) {
                $aDate->sub(new DateInterval('P1M'));
            }
            $this->monthlyIterator->getInnerIterator()->getInnerIterator()->setStartDate($aDate);
            $this->monthlyIterator->rewind();
            $beginningOfQuarter = clone $this->monthlyIterator->current();

            $aDate->setDate($date->format('Y'), 1, 3);
            $this->monthlyIterator->getInnerIterator()->getInnerIterator()->setStartDate($aDate);
            $this->monthlyIterator->rewind();
            $beginningOfYear = clone $this->monthlyIterator->current();

            $calculated_formulas = $watchlist->getCalculatedFormulas();
            $params = [
              'date' => $date,
              'wk_start' => $beginningOfWeek,
              'mo_start' => $beginningOfMonth,
              'qtr_start' => $beginningOfQuarter,
              'yr_start' => $beginningOfYear,
              'pos_score' => 6
            ];
            array_walk($calculated_formulas, function (&$data, $symbol, &$params) {
                $instrument = $this->em->getRepository(Instrument::class)->findOneBy(['symbol' => $symbol]);
                $moreData = ['Wk delta P' => 0, 'Mo delta P' => 0, 'Qrtr delta P' => 0, 'Yr delta P' => 0];
                if ($instrument) {
                    $weekStartP  = $this->em->getRepository(History::class)
                      ->findOneBy(['instrument' => $instrument, 'timestamp' => $params['wk_start']]);
                    $moreData['Wk delta P'] = ($data['P'] - $weekStartP->getOpen()) / $weekStartP->getOpen() * 100;
                    $monthStartP = $this->em->getRepository(History::class)
                      ->findOneBy(['instrument' => $instrument, 'timestamp' => $params['mo_start']]);
                    $moreData['Mo delta P'] = ($data['P'] - $monthStartP->getOpen()) / $monthStartP->getOpen() * 100;
                    $quarterStartP = $this->em->getRepository(History::class)
                      ->findOneBy(['instrument' => $instrument, 'timestamp' => $params['qtr_start']]);
                    $moreData['Qrtr delta P'] = ($data['P'] - $quarterStartP->getOpen()) /
                      $quarterStartP->getOpen() * 100;
                    $yearStartP = $this->em->getRepository(History::class)
                      ->findOneBy(['instrument' => $instrument, 'timestamp' => $params['yr_start']]);
                    $moreData['Yr delta P'] = ($data['P'] - $yearStartP->getOpen()) / $yearStartP->getOpen() * 100;

                    if (0 == $params['pos_score']) {
                        $params['pos_score']--;
                    }
                    $moreData['Hist Pos Score']['T'] = $params['pos_score']--;
                }
                $data = array_merge($data, $moreData);
            }, $params);

            // Retrieve and record prior sector positions
            $getSectorTable = new Criteria(Criteria::expr()->eq('attribute', 'sector-table'));

            $this->tradeDayIterator->getInnerIterator()->setStartDate($date)->setDirection(-1);
            $this->tradeDayIterator->getInnerIterator()->rewind();
            for ($daysBack = 1; $daysBack < 5; $daysBack++) {
                $this->tradeDayIterator->next();
                $pastDate = $this->tradeDayIterator->current();

                $pastStudy = $this->em->getRepository(Study::class)->findOneBy(['date' => $pastDate]);
                if ($pastStudy) {
                    /** @var ArrayAttribute | false  $sectorTableArrayAttr */
                    $sectorTableArrayAttr = $pastStudy->getArrayAttributes()->matching($getSectorTable)->first();
                    if ($sectorTableArrayAttr) {
                        $pastSectorTable = $sectorTableArrayAttr->getValue();
                        // this is needed for summary later
                        if (1 == $daysBack) {
                            $pastSectorTablePrevT = $pastSectorTable;
                        }
                        foreach ($pastSectorTable['table'] as $symbol => $data) {
                            $calculated_formulas[$symbol]['Hist Pos Score']['T-' . $daysBack] =
                              $data['Hist Pos Score']['T'];
                        }
                    } else {
                        array_walk($calculated_formulas, function (&$data, $symbol, $daysBack) {
                            $data['Hist Pos Score']['T-' . $daysBack] = 0;
                        }, $daysBack);
                    }
                } else {
                    array_walk($calculated_formulas, function (&$data, $symbol, $daysBack) {
                        $data['Hist Pos Score']['T-' . $daysBack] = 0;
                    }, $daysBack);
                }
            }

            array_walk($calculated_formulas, function (&$data, $symbol) {
                $data['Pos Score Sum'] = array_sum($data['Hist Pos Score']);
                $data['Pos Score Grad'] = $data['Hist Pos Score']['T'] - $data['Hist Pos Score']['T-4'];
            });

            $sectorTable['table'] = $calculated_formulas;

            // Add Summary
            $summary['sum']['delta P prcnt'] = array_sum(array_column($calculated_formulas, 'delta P prcnt'));
            $summary['sum']['delta P(5) prcnt'] = array_sum(array_column($calculated_formulas, 'delta P(5) prcnt'));
            $summary['sum']['Wk delta P'] = array_sum(array_column($calculated_formulas, 'Wk delta P'));
            $summary['sum']['Mo delta P'] = array_sum(array_column($calculated_formulas, 'Mo delta P'));
            $summary['sum']['Qrtr delta P'] = array_sum(array_column($calculated_formulas, 'Qrtr delta P'));
            $summary['sum']['Yr delta P'] = array_sum(array_column($calculated_formulas, 'Yr delta P'));

            if (isset($pastSectorTablePrevT)) {
                $summary['up_down_tick']['delta P(5) prcnt'] = $summary['sum']['delta P(5) prcnt'] -
                  $pastSectorTablePrevT['summary']['sum']['delta P(5) prcnt'];
                $summary['up_down_tick']['Wk delta P'] = $summary['sum']['Wk delta P'] -
                  $pastSectorTablePrevT['summary']['sum']['Wk delta P'];
                $summary['up_down_tick']['Mo delta P'] = $summary['sum']['Mo delta P'] -
                  $pastSectorTablePrevT['summary']['sum']['Mo delta P'];
                $summary['up_down_tick']['Qrtr delta P'] = $summary['sum']['Qrtr delta P'] -
                  $pastSectorTablePrevT['summary']['sum']['Qrtr delta P'];
                $summary['up_down_tick']['Yr delta P'] = $summary['sum']['Yr delta P'] -
                  $pastSectorTablePrevT['summary']['sum']['Yr delta P'];
            } else {
                $summary['up_down_tick']['delta P(5) prcnt'] = $summary['sum']['delta P(5) prcnt'] - 0;
                $summary['up_down_tick']['Wk delta P'] = $summary['sum']['Wk delta P'] - 0;
                $summary['up_down_tick']['Mo delta P'] = $summary['sum']['Mo delta P'] - 0;
                $summary['up_down_tick']['Qrtr delta P'] = $summary['sum']['Qrtr delta P'] - 0;
                $summary['up_down_tick']['Yr delta P'] = $summary['sum']['Yr delta P'] - 0;
            }

            $sectorTable['summary'] = $summary;
        } catch (Exception $e) {
            throw new StudyException($e->getMessage());
        }

        StudyArrayAttributeRepository::createArrayAttr($this->study, 'sector-table', $sectorTable);

        return $this;
    }

    /**
     * Builds charts for symbols in watchlist using same style. Saves all charts to disk. Chart files have names
     * similar to: LIN_20200515.png
     * @param Watchlist $watchlist
     * @param StyleInterface $style
     * @return StudyBuilder
     * @throws PriceHistoryException
     * @throws StudyException
     * @throws ChartException
     */
    public function buildCharts(Watchlist $watchlist, StyleInterface $style): StudyBuilder
    {
        $instruments = $watchlist->getInstruments();
        $studyDate = $this->getStudy()->getDate();

        foreach ($instruments as $instrument) {
            $this->tradeDayIterator->getInnerIterator()->setStartDate($studyDate)->setDirection(-1);
            $offset = 100;
            $limitIterator = new LimitIterator($this->tradeDayIterator, $offset, 1);
            $limitIterator->rewind();
            $fromDate = $limitIterator->current();
            $interval = History::getOHLCVInterval(History::INTERVAL_DAILY);
            $history = $this->em->getRepository(History::class)
              ->retrieveHistory($instrument, $interval, $fromDate, $studyDate);
            if (!$history) {
                throw new StudyException(
                    sprintf(
                        'Could not retrieve price history from %s through %s for daily interval for instrument %s',
                        $fromDate->format('Y-m-d'),
                        $studyDate->format('Y-m-d'),
                        $instrument->getSymbol()
                    )
                );
            }

            $style->categories = array_map(function ($p) {
                return $p->getTimestamp()->format('m/d');
            }, $history);
            $keys = array_keys($history);
            $lastPriceHistoryKey = array_pop($keys);
            $this->tradeDayIterator->getInnerIterator()->setStartDate($history[$lastPriceHistoryKey]->getTimeStamp())
              ->setDirection(1);
            $this->tradeDayIterator->rewind();
            $keys = array_keys($style->categories);
            $key = array_pop($keys);

            while ($key <= $style->x_axis['max']) {
                $style->categories[$key] = $this->tradeDayIterator->current()->format('m/d');
                $this->tradeDayIterator->next();
                $key++;
            }

            $style->chart_path = sprintf('public/%s_%s.png', $instrument->getSymbol(), $studyDate->format('Ymd'));
            $chart = ChartFactory::create($style, $history);
            $chart->save_chart();
        }

        return $this;
    }
}