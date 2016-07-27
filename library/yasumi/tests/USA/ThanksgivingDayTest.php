<?php
/**
 *  This file is part of the Yasumi package.
 *
 *  Copyright (c) 2015 - 2016 AzuyaLabs
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 *
 *  @author Sacha Telgenhof <stelgenhof@gmail.com>
 */

namespace Yasumi\tests\USA;

use DateTime;
use DateTimeZone;
use Yasumi\Holiday;
use Yasumi\tests\YasumiTestCaseInterface;

/**
 * Class for testing Thanksgiving Day in the USA.
 */
class ThanksgivingDayTest extends USABaseTestCase implements YasumiTestCaseInterface
{
    /**
     * The name of the holiday
     */
    const HOLIDAY = 'thanksgivingDay';

    /**
     * The year in which the holiday was first established
     */
    const ESTABLISHMENT_YEAR = 1863;

    /**
     * Tests Thanksgiving Day on or after 1863. Thanksgiving Day is celebrated since 1863 on the fourth Thursday
     * of November.
     */
    public function testThanksgivingDayOnAfter1863()
    {
        $year = $this->generateRandomYear(self::ESTABLISHMENT_YEAR);
        $this->assertHoliday(self::REGION, self::HOLIDAY, $year,
            new DateTime("fourth thursday of november $year", new DateTimeZone(self::TIMEZONE)));
    }

    /**
     * Tests Thanksgiving Day before 1863. ThanksgivingDay Day is celebrated since 1863 on the fourth Thursday
     * of November.
     */
    public function testThanksgivingDayBefore1863()
    {
        $this->assertNotHoliday(self::REGION, self::HOLIDAY,
            $this->generateRandomYear(1000, self::ESTABLISHMENT_YEAR - 1));
    }

    /**
     * Tests translated name of the holiday defined in this test.
     */
    public function testTranslation()
    {
        $this->assertTranslatedHolidayName(self::REGION, self::HOLIDAY,
            $this->generateRandomYear(self::ESTABLISHMENT_YEAR), [self::LOCALE => 'Thanksgiving Day']);
    }

    /**
     * Tests type of the holiday defined in this test.
     */
    public function testHolidayType()
    {
        $this->assertHolidayType(self::REGION, self::HOLIDAY, $this->generateRandomYear(self::ESTABLISHMENT_YEAR),
            Holiday::TYPE_NATIONAL);
    }
}
