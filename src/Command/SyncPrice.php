<?php
/**
 * This file is part of the Trade Helper package.
 *
 * (c) Alex Kay <alex110504@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;


use App\Entity\Instrument;
use App\Exception\PriceHistoryException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use League\Csv\Reader;
use Psr\Log\LogLevel;
use League\Csv\Statement;

/**
 * Class SyncPrice
 * On Screen chatter legend:
 * NoPH - No Price History exists in DB
 * @package App\Command
 */
class SyncPrice extends Command
{
    const DEFAULT_PATH = 'data/source/y_universe.csv';

    const START_DATE = '2011-01-01';

    const MAX_DELAY = 10;

    /**
     * @var Doctrine\ORM\EntityManager
     */
    protected $em;

    /**
     * @var League\Csv\Reader
     */
    protected $csv;

    /**
     * @var \App\Service\PriceHistory\OHLCV\Yahoo
     */
    protected $priceProvider;

    /**
     * @var Psr/Log/LoggerInterface
     */
    protected $logger;

    /**
     * On screen chatter level
     * @var int
     */
    protected $chatter;

    /**
     * Delay in seconds between queries to API
     * @var int
     */
    protected $delay;

    public function __construct(
        \Symfony\Bridge\Doctrine\RegistryInterface $doctrine,
        \App\Service\PriceHistory\OHLCV\Yahoo $priceProvider,
        \Psr\Log\LoggerInterface $logger,
        $chatter
    )
    {
        $this->em = $doctrine->getManager();
        $this->priceProvider = $priceProvider;
        $this->logger = $logger;
        $this->chatter = $chatter;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('price:sync');

        $this->setDescription('Syncs up prices for a list of stocks from a price provider');

        $this->setHelp(
            <<<'EOT'
Uses a csv file with header and list of symbols to define list of symbols to work on. Symbols found in the csv file must
 also be imported as instruments. You can use data fixtures to import all instruments. Header must contain column titles
 contained in the current file data/source/y_universe.csv.
 The command writes everything into application log, regardless of verbosity settings. No output is sent to the screen
 by default either. If you want screen output, use -v option.
 Price records in history may be considered as either a Quote or Price History. 
 Records with time which is not midnight are considered Quotes. Timezone is not saved in db, so the retrieved DateTime
 objects assume (and set) script's timezone according to the exchange. All downloaded records which are historical have 
 their time set to midnight.
 There are several scenarios possible:
 A) If market is open, to update last quote with the latest use --stillT-saveQ option.
 B) If market is closed, and you still have last records saved as Quotes, i.e. last update was done during prior T and 
 you ended up with a bunch of quotes saved for mid-trading day. These kinds of records are historical records today.
 Then use --prevT-QtoH option.
 C) If you are running this on trading day, sometime after midnight NYC time and before market open, all downloaded
 history records will have price for last T. In this case just run the command without the above 2 options.
 
 So it is best to run price update during trading hours and after market close but before midnight NYC with 
 --stillT-saveQ option.
 After midnight NYC time and before market open without these 2 options.
 
 To conserve memory, you can run this command with --chunk option:
 For 1 Core 1 G of RAM instance --chunk=50
 
EOT
        );

        $this->addUsage('[-v] [--prevT-QtoH] [--stillT-saveQ] [--delay=X|random] [--offset=X] [--chunk=X] [data/source/y_universe.csv]');

        $this->addArgument('path', InputArgument::OPTIONAL, 'path/to/file.csv with list of symbols to work on', self::DEFAULT_PATH);

        $this->addOption('prevT-QtoH', null, InputOption::VALUE_NONE, 'If prev T in history is quote, will download history and will replace');
        $this->addOption('stillT-saveQ', null, InputOption::VALUE_NONE, 'Downloaded Quotes for today will keep replacing last P in history for today');
        $this->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay in seconds between each query to API or word random for random delay no longer than 10 seconds.');
        $this->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Number of records to process in one chunk');
        $this->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Starting offset, which includes header count. Header has offset=0');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if ($delay = $input->getOption('delay')) {
            if (is_numeric($delay)) {
                $this->delay = $delay;
            } elseif ('random' == $delay) {
                $this->delay = -1;
            } else {
                $this->delay = null;
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repository = $this->em->getRepository(Instrument::class);

        $today = new \DateTime();

        $csvFile = $input->getArgument('path');
        $csv = Reader::createFromPath($csvFile, 'r');
        $csv->setHeaderOffset(0);
        $statement = new Statement();

        if ($input->getOption('offset')) {
            $offset = (int)$input->getOption('offset') - 1;
        } else {
            $offset = 0;
        }

        $statement = $statement->offset($offset);

        if ($chunk = $input->getOption('chunk')) {
            $statement = $statement->limit($chunk);
        }

        $records = $statement->process($csv);

        while ($records->count() > 0) {
            $queue = new ArrayCollection();

            foreach ($records as $key => $record) {
                $logMsg = sprintf('%s: ', $record['Symbol']);
                $screenMsg = sprintf('%3.3d ', $key).$logMsg;
                $options = ['interval' => 'P1D'];
                $noopFlag = true;

                $instrument = $repository->findOneBySymbol($record['Symbol']);

                try {
                    if ($instrument) {
                        $exchange = $this->priceProvider->getExchangeForInstrument($instrument);

                        $prevT = $exchange->calcPreviousTradingDay($today)->setTime(0, 0, 0);

                        $criterion = new Criteria(Criteria::expr()->eq('symbol', $instrument->getSymbol()));

                        // history exists? Download if missing.
                        $lastPrice = $this->priceProvider->retrieveClosingPrice($instrument);

                        if (!$lastPrice) {
                            $logMsg .= 'No Price History found ';
                            $screenMsg .= 'noPH ';
                            $fromDate = new \DateTime(self::START_DATE);

                            $this->addMissingHistory($instrument, $fromDate, $today, $options);
                            $noopFlag = false;

                            $lastPrice = $this->priceProvider->retrieveClosingPrice($instrument);

                            $logMsg .= sprintf(
                                'History saved from %s through %s ',
                                $fromDate->format('Y-m-d H:i:s'),
                                $lastPrice->getTimestamp()->format('Y-m-d H:i:s')
                            );
                            $screenMsg .= sprintf(
                                'saved%s...%s ',
                                $fromDate->format('Ymd'),
                                $lastPrice->getTimestamp()->format('Ymd')
                            );
                        }

                        // gaps exist between today and last day of history? download missing history
                        if ($lastPrice->getTimestamp() < $prevT) {
                            $logMsg .= sprintf(
                                'Gap determined: lastP=%s prevT=%s ',
                                $lastPrice->getTimestamp()->format('Y-m-d H:i:s'),
                                $prevT->format('Y-m-d H:i:s')
                            );
                            $screenMsg .= sprintf('gap_after%s ', $lastPrice->getTimestamp()->format('Ymd'));
                            $gapStart = clone $lastPrice;

                            $this->addMissingHistory(
                                $instrument,
                                $lastPrice->getTimestamp()->setTime(0, 0, 0),
                                $today,
                                $options
                            );

                            $noopFlag = false;

                            $lastPrice = $this->priceProvider->retrieveClosingPrice($instrument);

                            $logMsg .= sprintf(
                                'Added missing history lastP=%s ',
                                $lastPrice->getTimestamp()->format('Y-m-d H:i:s')
                            );
                            $screenMsg .= sprintf(
                                'saved%s...%s ',
                                $gapStart->getTimestamp()->format('Ymd'),
                                $lastPrice->getTimestamp()->format('Ymd')
                            );
                        }

                        // If last price's time is not 0 hours, 0 minutes and 0 seconds, then it is a quote and may need to be downloaded
                        // and replaced as history.
                        if ($lastPrice->getTimestamp()->format('Ymd') == $prevT->format('Ymd')) {
                            // need to check option here to replace quotes with history records
                            if ($input->getOption('prevT-QtoH') && ($lastPrice->getTimestamp()->format(
                                        'H'
                                    ) + $lastPrice->getTimestamp()->format('i') + $lastPrice->getTimestamp()->format(
                                        's'
                                    ) > 0)) {
                                $this->addMissingHistory(
                                    $instrument,
                                    $lastPrice->getTimestamp()->setTime(0, 0, 0),
                                    $today,
                                    $options
                                );

                                $noopFlag = false;

                                $logMsg .= sprintf('downloaded and replaced last P from history ');
                                $screenMsg .= sprintf('replacedQtoH ');
                            }

                            if ($input->getOption('stillT-saveQ')) {
                                if ($queue->matching($criterion)->isEmpty()) {
                                    $queue->add($instrument);
                                    $logMsg .= 'queued for quotes download ';
                                    $screenMsg .= 'queued_for_Q ';
                                }
                            }
                        }

                        if ($input->getOption('stillT-saveQ') && $lastPrice->getTimestamp()->format(
                                'Ymd'
                            ) == $today->format('Ymd')) {
                            if ($queue->matching($criterion)->isEmpty()) {
                                $queue->add($instrument);
                                $logMsg .= 'queued for quotes download ';
                                $screenMsg .= 'in_queue_forQ ';
                            }
                        }
                    } else {
                        $logMsg .= 'not found';
                        $screenMsg = $logMsg;
                    }
                } catch (PriceHistoryException $e) {
                    $logMsg .= $e->getMessage();
                    switch ($e->getCode()) {
                        case 1:
                            $screenMsg .= 'Missing exchange name';
                            break;
                        case 2:
                            $screenMsg .= 'API_fail';
                            break;
                        default:
                            $screenMsg .= $e->getMessage();
                    }
                } finally {
                    if ($noopFlag) {
                        $logMsg .= 'NOOP';
                        $screenMsg .= 'NOOP';
                    }
                    $this->logAndSay($output, $logMsg, $screenMsg);
                }
            }

            // check for one shot download and updated latest prices anyway:
            if (!$queue->isEmpty()) {
                $logMsg = sprintf(PHP_EOL.'Will now download quotes for %d symbols', $queue->count());
                $screenMsg = $logMsg;
                $this->logAndSay($output, $logMsg, $screenMsg);

                $quotes = $this->priceProvider->getQuotes($queue->toArray());

                $logMsg = sprintf('Downloaded %d quotes', count($quotes));
                $screenMsg = $logMsg;
                $this->logAndSay($output, $logMsg, $screenMsg);

                foreach ($quotes as $quote) {
                    $instrument = $quote->getInstrument();
                    $logMsg = sprintf('%s: ', $instrument->getSymbol());
                    $screenMsg = $logMsg;
                    if ($exchange->isOpen($today)) {
                        $this->priceProvider->saveQuote($instrument, $quote);
                        $this->priceProvider->addQuoteToHistory($quote);

                        $logMsg .= sprintf(
                            'saved Quote to History %s $%0.2f ',
                            $quote->getTimestamp()->format('Y-m-d H:i:s'),
                            $quote->getClose()
                        );
                        $screenMsg .= sprintf('savedQ=%0.2f ', $quote->getClose());
                    } else {
                        $this->priceProvider->removeQuote($instrument);
                        $closingPrice = $this->priceProvider->castQuoteToHistory($quote);
                        $this->priceProvider->addClosingPriceToHistory($closingPrice);

                        $logMsg .= sprintf(
                            'saved Quote as Closing Price to History %s $%0.2f ',
                            $quote->getTimestamp()->format('Y-m-d H:i:s'),
                            $quote->getClose()
                        );
                        $screenMsg .= sprintf('savedQ=%0.2f as Closing P ', $quote->getClose());
                    }

                    $this->logAndSay($output, $logMsg, $screenMsg);
                }
            }

            if ($chunk > 0) {
                $offset += $chunk;
            } else {
                $offset += $key;
            }
            $statement = $statement->offset($offset);
            $records = $statement->process($csv);
        }

        $logMsg = PHP_EOL . 'Finished';
        $screenMsg = $logMsg;
        $this->logAndSay($output, $logMsg, $screenMsg);
    }

    /**
     * @param $instrument
     * @param $fromDate
     * @param $today
     * @param $options
     * @throws \App\Exception\PriceHistoryException
     * @throws \Scheb\YahooFinanceApi\Exception\ApiException
     */
    private function addMissingHistory($instrument, $fromDate, $today, $options)
    {
        if ($this->delay > 0) {
            sleep($this->delay);
        } elseif ($this->delay < 0) {
            sleep(rand(0,self::MAX_DELAY));
        }

        $history = $this->priceProvider->downloadHistory($instrument, $fromDate, $today, $options);

        $this->priceProvider->addHistory($instrument, $history);
    }


    /**
     * @param Symfony\Component\Console\Output\OutputInterface $output
     * @param string $logMsg
     * @param string $screenMsg
     */
    private function logAndSay($output, $logMsg, $screenMsg) {
        $this->logger->log(LogLevel::DEBUG, $logMsg);
        if ($output->getVerbosity() >= $this->chatter ) {
            $output->writeln($screenMsg);
        }
    }
}