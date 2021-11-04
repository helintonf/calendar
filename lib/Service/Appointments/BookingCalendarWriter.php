<?php

declare(strict_types=1);

/**
 * Calendar App
 *
 * @copyright 2021 Anna Larch <anna.larch@gmx.net>
 *
 * @author Anna Larch <anna.larch@gmx.net>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Calendar\Service\Appointments;

use OCA\Calendar\Db\AppointmentConfig;
use OCA\DAV\CalDAV\CalendarImpl;
use OCP\Calendar\Exceptions\CalendarException;
use OCP\Calendar\IManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;

class BookingCalendarWriter {

	/** @var IConfig */
	private $config;

	/** @var IManager */
	private $manager;

	/** @var IUserManager */
	private $userManager;

	/** @var ISecureRandom */
	private $random;

	public function __construct(IConfig $config,
								IManager $manager,
								IUserManager $userManager,
								ISecureRandom $random) {
		$this->config = $config;
		$this->manager = $manager;
		$this->userManager = $userManager;
		$this->random = $random;
	}

	/**
	 * @param AppointmentConfig $config
	 * @param \DateTime $start
	 * @param string $name
	 * @param string $email
	 * @param string $description
	 *
	 */
	public function write(AppointmentConfig $config, \DateTime $start, string $name, string $email, string $description) : void {
		$calendar = current($this->manager->getCalendarsForPrincipal($config->getPrincipalUri(), [$config->getTargetCalendarUri()]));
		if (!($calendar instanceof CalendarImpl)) {
			throw new RuntimeException('Could not find a public writable calendar for this principal');
		}

		$organizer = $this->userManager->get($config->getUserId());

		if ($organizer === null) {
			throw new RuntimeException('Organizer not registered user for this instance');
		}

		$vcalendar = new VCalendar([
			'CALSCALE' => 'GREGORIAN',
			'VERSION' => '2.0',
			'VEVENT' => [
				'SUMMARY' => $config->getName(),
				'REQUEST-STATUS' => '2.0;Success',
				'DESCRIPTION' => $description,
				'DTSTART' => $start,
				'DTEND' => $start->setTimestamp($start->getTimestamp() + $config->getLength())
			]
		]);

		$vcalendar->VEVENT->add('ORGANIZER', $organizer->getEMailAddress(), [ 'CN' => $organizer->getDisplayName()]);
		$vcalendar->VEVENT->add('ATTENDEE', $organizer->getEMailAddress(), [ 'CN' => $organizer->getDisplayName(), 'RSVP' => false, 'PARTSTAT' => 'ACCEPTED']);
		$vcalendar->VEVENT->add('ATTENDEE', $email, ['CN' => $name, 'RSVP' => false, 'PARTSTAT' => 'ACCEPTED']);
		$vcalendar->VEVENT->add('X-NC-APPOINTMENT', $config->getToken());

		$filename = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$serialized = $vcalendar->serialize();
		try {
			$calendar->createFromString($filename . '.ics', $vcalendar->serialize());
		} catch (CalendarException $e) {
			throw new RuntimeException('Could not write to calendar', 0, $e);
		}
	}
}
