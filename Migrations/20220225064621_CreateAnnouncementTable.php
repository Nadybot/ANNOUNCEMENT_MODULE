<?php declare(strict_types=1);

namespace Unknown;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\User\Modules\ANNOUNCE_MODULE\AnnouncementController;

class CreateAnnouncementTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AnnouncementController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("name", 100);
			$table->text("content");
			$table->boolean("active");
			$table->string("created_by", 15);
			$table->integer("created_on");
			$table->integer("interval_between_channels")->default(5);
			$table->integer("interval_between_announcements")->default(1800);
			$table->integer("last_announcement")->nullable();
		});
	}
}
