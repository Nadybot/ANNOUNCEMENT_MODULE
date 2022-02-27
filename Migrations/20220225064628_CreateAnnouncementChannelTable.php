<?php declare(strict_types=1);

namespace Unknown;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\User\Modules\ANNOUNCE_MODULE\AnnouncementController;

class CreateAnnouncementChannelTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AnnouncementController::DB_TABLE_CHANNEL;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->integer("announcement_id");
			$table->string("channel", 100);
		});
	}
}
