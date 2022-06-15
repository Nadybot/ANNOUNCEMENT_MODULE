<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ANNOUNCE_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBRow;

class Announcement extends DBRow {
	public int $id;
	public string $name;
	public string $content = "TBD";
	public string $created_by;
	public bool $active = false;
	public int $created_on;
	public int $interval_between_channels = 5;
	public int $interval_between_announcements = 1800;
	public ?int $last_announcement = null;

	/**
	 * @var AnnouncementChannel[]
	 */
	#[NCA\DB\Ignore]
	public array $channels = [];

	public function __construct() {
		$this->created_on = time();
	}

	/**
	 * @return null|array<int>
	 * @phpstan-return null|array{int,int}
	 */
	public function getNextAnnouncement(): ?array {
		if ($this->last_announcement === null) {
			return count($this->channels) ? [time(), 0] : null;
		}
		$cycleTime = $this->interval_between_announcements
			+ max(0, count($this->channels) - 1) * $this->interval_between_channels;
		$cycles = (int)floor((time() - $this->last_announcement) / $cycleTime);
		$baseTime = $this->last_announcement + $cycles * $cycleTime;
		for ($i = 0; $i < count($this->channels); $i++) {
			$time = $baseTime + $this->interval_between_channels * ($i);
			if (time() <= $time) {
				return [$time, $i];
			}
		}
		return [$baseTime + $cycleTime, 0];
	}
}
