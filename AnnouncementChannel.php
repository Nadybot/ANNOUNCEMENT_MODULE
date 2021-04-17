<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ANNOUNCE_MODULE;

use Nadybot\Core\DBRow;

class AnnouncementChannel extends DBRow {
	public int $id;
	public int $announcement_id;
	public string $channel;
}
