<?php
/**
 * This file is part of the Trade Helper Online package.
 *
 * (c) 2019-2020  Alex Kay <alex110504@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Studies\MGWebGroup\MarketSurvey\DataFixtures;

use App\Entity\Study\Study;
use App\Entity\Watchlist;
use App\Service\Exchange\Equities\TradingCalendar;
use App\Studies\MGWebGroup\MarketSurvey\StudyBuilder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class StudyFixtures
 * Wachlist named watchlist_test must already be imported with WatchlistFixtures
 * @package App\Studies\MGWebGroup\MarketSurvey\DataFixtures
 */
class StudyFixtures extends Fixture implements FixtureGroupInterface
{
    const WATCHLIST_NAME = 'watchlist_test';
    const STUDY_NAME = 'test_market_study';

    /**
     * @var StudyBuilder
     */
    private $studyBuilder;

    /**
     * @var TradingCalendar
     */
    private $tradingCalendar;

    public function __construct(
      StudyBuilder $studyBuilder,
      TradingCalendar $tradingCalendar
    )
    {
        $this->studyBuilder = $studyBuilder;
        $this->tradingCalendar = $tradingCalendar;
    }

    public static function getGroups(): array
    {
        return ['mgweb_studies'];
    }

    /**
     * Creates 20 studies without historical score table in them for dates: 2020-04-17 through 2020-05-14.
     * Creates 1 full study with historical score tables in them for 2020-05-15
     * @param ObjectManager $manager
     * @throws \App\Exception\PriceHistoryException
     * @throws \App\Studies\MGWebGroup\MarketSurvey\Exception\StudyException
     * @throws \MathPHP\Exception\BadDataException
     * @throws \MathPHP\Exception\OutOfBoundsException
     */
    public function load(ObjectManager $manager)
    {
        $output = new ConsoleOutput();

        $watchlist = $manager->getRepository(Watchlist::class)->findOneBy(['name' => self::WATCHLIST_NAME]);

        // Create 20 studies
        $periodDays = 20;
        $date = new \DateTime('2015-05-15');
        $this->tradingCalendar->getInnerIterator()->setStartDate($date)->setDirection(-1);
        $this->tradingCalendar->getInnerIterator()->rewind();
        for ($i = 1; $i <= $periodDays; $i++) {
            $this->tradingCalendar->next();
        }
        $date = $this->tradingCalendar->current();
        $this->studyBuilder->createStudy($date, self::STUDY_NAME);
        $pastStudy = null;
        for ($i = 1; $i <= $periodDays; $i++) {
            fwrite(STDOUT, sprintf('%2d Calculating Market Breadth...', $i));
            $startTimestamp = time();
            $this->studyBuilder->calculateMarketBreadth($watchlist);
            $endTimestamp = time();
            fwrite(STDOUT, sprintf('done %d s', $endTimestamp - $startTimestamp).PHP_EOL);
            $this->studyBuilder->calculateScoreDelta($pastStudy);
            $this->studyBuilder->buildActionableSymbolsWatchlist();
            if ($pastStudy) {
                $this->studyBuilder->figureInsideBarBOBD($pastStudy, $date);
                $this->studyBuilder->figureASBOBD($pastStudy, $date);
            }

            $pastStudy = $this->studyBuilder->getStudy();
            $manager->persist($pastStudy);
            $manager->flush();

            $this->tradingCalendar->getInnerIterator()->setStartDate($date)->setDirection(1);
            $this->tradingCalendar->getInnerIterator()->rewind();
            $this->tradingCalendar->next();
            $date = $this->tradingCalendar->current();

            $this->studyBuilder->createStudy($date, self::WATCHLIST_NAME);
        }

        // create and persist full study for 2020-05-15
        $pastDate = new \DateTime('2020-05-14');
        $pastStudy = $manager->getRepository(Study::class)->findOneBy(['date' => $pastDate]);

        if ($pastStudy) {
            fwrite(STDOUT, sprintf('%2d Calculating Market Breadth...', $i));
            $startTimestamp = time();
            $this->studyBuilder->calculateMarketBreadth($watchlist);
            $endTimestamp = time();
            fwrite(STDOUT, sprintf('done %d s', $endTimestamp - $startTimestamp).PHP_EOL);
            $this->studyBuilder->calculateScoreDelta($pastStudy);
            $this->studyBuilder->figureInsideBarBOBD($pastStudy, $date);
            $this->studyBuilder->figureASBOBD($pastStudy, $date);
            $this->studyBuilder->buildActionableSymbolsWatchlist();
            $this->studyBuilder->buildMarketScoreTableForRollingPeriod($periodDays);
            $this->studyBuilder->buildMarketScoreTableForMTD();

            $manager->persist($this->studyBuilder->getStudy());
            $manager->flush();
        } else {
            $output->writeln(sprintf('<error>ERROR: </error> Could not find study for %s', $pastDate->format('Y-m-d')));
        }
    }
}
