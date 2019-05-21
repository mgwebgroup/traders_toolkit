<?php
/**
 * This file is part of the Trade Helper package.
 *
 * (c) Alex Kay <alex110504@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\PriceHistory\PriceProviderInterface;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\ApiClientFactory;
use Scheb\YahooFinanceApi\Exception\ApiException;
use App\Entity\OHLCVHistory;
use App\Service\Exchange_Equities;


class OHLCV_Yahoo implements PriceProviderInterface
{
	/**
	* Currently supported intervals from the Price Provider 
	* These follow interval_spec for the \DateInterval class
	*/
	public $intervals = [
		// 'PT1M',
		// 'PT2M',
		// 'PT3M',
		// 'PT3M',
		// 'PT6M',
		// 'PT10M',
		// 'PT15M',
		// 'PT30M',
		// 'PT60M',
		// 'PT120M',
		'P1D',
		'P1W',
		'P1M',
		// 'P1Y',
	]; 

	private $priceProvider;

	private $saveQuotePath;

	private $exchangeEquities;


	public function __construct(Exchange_Equities $exchangeEquities)
	{
		$this->priceProvider = ApiClientFactory::createApiClient();
		$this->exchangeEquities = $exchangeEquities;
	}


	/**
	 * @param array $options ['interval' => 'P1M|P1W|P1D' ]
	 */
	public function downloadHistory($instrument, $fromDate, $toDate, $options)
	{
		if ('test' == $_SERVER['APP_ENV']) {
			$today = $_SERVER['TODAY'];
		} else {
			$today = date('Y-m-d');
		}
		// var_dump($today); exit();
		// see if $toDate is today
		if ($toDate->format('Y-m-d') == $today) {
			$toDate = $this->exchangeEquities->calcPreviousTradingDay($toDate);
		}

		if (isset($options['interval']) && in_array($options['interval'], $this->intervals)) {
			switch ($options['interval']) {
				case 'P1M':
					$apiInterval = ApiClient::INTERVAL_1_MONTH;
					$interval = new \DateInterval('P1M');
					break;
				case 'P1W':
					$apiInterval = ApiClient::INTERVAL_1_WEEK;
					$interval = new \DateInterval('P1W');
					break;
				case 'P1D':
				default:
					$apiInterval = ApiClient::INTERVAL_1_DAY;
					$interval = new \DateInterval('P1D');
			}
		} else {
			$apiInterval = ApiClient::INTERVAL_1_DAY;
		}

		try {
			$result = $this->priceProvider->getHistoricalData($instrument->getSymbol(), $apiInterval, $fromDate, $toDate);
			// var_dump($result);
			array_walk($result, function(&$v, $k, $data) {
				$OHLCVHistory = new OHLCVHistory();
				$OHLCVHistory->setOpen($v->getOpen());
				$OHLCVHistory->setHigh($v->getHigh());
				$OHLCVHistory->setLow($v->getLow());
				$OHLCVHistory->setClose($v->getClose());
				$OHLCVHistory->setVolume($v->getVolume());
				$OHLCVHistory->setTimestamp($v->getDate());
				$OHLCVHistory->setInstrument($data[0]);
				$OHLCVHistory->setTimeinterval($data[1]);
				$v = $OHLCVHistory;
			}, [$instrument, $interval]);

			return $result;

		} catch (ApiException $e) {
			// Log error
		}
	}

	public function addHistory($instrument, $history) {}
 
 	public function exportHistory($history, $path) {}
 
 	public function retrieveHistory($instrument, $fromDate, $toDate) {}
 
 	public function downloadQuote($instrument) {}
 
 	public function saveQuote($quote) {}
 
 	public function addQuoteToHistory($quote, $history) {}
 
	public function retrieveQuote($instrument) {}

	public function downloadClosingPrice($instrument) {}
}