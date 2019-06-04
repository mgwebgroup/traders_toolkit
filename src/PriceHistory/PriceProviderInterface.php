<?php
/**
 * This file is part of the Trade Helper package.
 *
 * (c) Alex Kay <alex110504@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\PriceHistory;

/**
 * Price provider can be any style of price data: OHLCV, ticks, japanese hoopla, etc.
 */
interface PriceProviderInterface
{
	/**
	 * Downloads historical price information from a provider. Historical means prices
	 * from a given date and including last trading day before today. If today is a
	 * trading day, it will not be included. Use downloadQuote (for open trading hours),
	 * and downloadClosingPrice(for past trading hours).
	 * Downloaded history must be sorted from earliest date (the first element) to the
	 *  latest (the last element).
	 * @param App\Entity\Instrument $instrument
	 * @param DateTime $fromDate
	 * @param DateTime $toDate
	 * @param array $options (example: ['interval' => 'P1D'])
	 * @throws PriceHistoryException 
	 * @return array with price history compatible with chosen storage format (Doctrine Entities, csv records, etc.)
	 */
	public function downloadHistory($instrument, $fromDate, $toDate, $options);

	/**
	 * Will add new history to the stored history.
	 * All records in old history which start from the earliest date in $history will be deleted, with the new
	 *  records from $history written in.
	 * @param App\Entity\Instrument $instrument
	 * @param array $history with price history compatible with chosen storage format (Doctrine Entities, csv records, etc.)
	 */
 	public function addHistory($instrument, $history);
 
 	public function exportHistory($history, $path, $options);
 
 	/**
 	 * Retrieves price history for an instrument from a storage
 	 * @param App\Entity\Instrument $instrument
 	 * @param DateTime $fromDate
 	 * @param DateTime $toDate
 	 * @param array $options (example: ['interval' => 'P1D'])
 	 * @return array with price history compatible with chosen storage format (Doctrine Entities, csv records, etc.)
 	 */
 	public function retrieveHistory($instrument, $fromDate, $toDate, $options);
 
 	/**
 	 * Quotes are downloaded when a market is open
 	 * @param App\Entity\Instrument $instrument
 	 * @return App\Entity\Quote when market is open or null if market is closed.
  	 */
 	public function downloadQuote($instrument);
 
 	/**
 	 * Saves given quote in storage. For any given instrument, only one quote supposed to be saved in storage.
 	 * If this function is called with existing quote already in storage, existing quote will be reomoved, and
 	 * new one saved.
 	 * @param App\Entity\Instrument
 	 * @param App\Entity\OHLCVQuote
 	 */
 	public function saveQuote($symbol, $data);
 
 	public function addQuoteToHistory($quote, $history);
 
 	/**
 	 * Retrieves quote from storage. Only one quote per instrument is supposed to be in storage. See saveQuote above
 	 * App\Entity\Instrument $instrument
 	 */
	public function retrieveQuote($instrument);

	/**
	 * Closing Prices are downloaded when market is closed and will return values
	 * for the closing price on last known trading day.
	 * @param App\Entity\Instrument $instrument
	 * @return App\Entity\History when market is closed or null if market is open.
	 */
	public function downloadClosingPrice($instrument);

}