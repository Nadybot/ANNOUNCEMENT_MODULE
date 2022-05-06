<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ANNOUNCE_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ConfigFile,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Text,
	Util,
};

use function Safe\file_get_contents;
use function Safe\unpack;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command:     'announcement',
		accessLevel: 'member',
		description: 'See a list of all announcements',
		alias:       'announcements',
	),
	NCA\DefineCommand(
		command:     AnnouncementController::CMD_ANNOUNCEMENT_ADMIN,
		accessLevel: 'mod',
		description: 'Modify the announcements',
	)
]
class AnnouncementController extends ModuleInstance {
	public const DB_TABLE = "announcement_<myname>";
	public const DB_TABLE_CHANNEL = "announcement_channel_<myname>";
	public const CMD_ANNOUNCEMENT_ADMIN = "announcement admin";

	public const TYPE_TOWER = 10;
	public const TYPE_TOUR = 12;
	public const TYPE_SHOPPING = 134;
	public const TYPE_OOC = 135;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * All currently enabled announcements
	 * We keep these loaded for performance reasons
	 * @var Announcement[]
	 */
	protected array $announcements = [];

	/**
	 * Unix timestamp of the next announcement
	 * This is pre-calculated, so the timer is more performant
	 */
	protected ?int $nextAnnouncement = null;

	#[NCA\Setup]
	public function setup(): void {
		$this->loadAnnouncements();
	}

	#[NCA\Event(
		name: "timer(1sec)",
		description: "Send out announcements",
	)]
	public function sendDueAnnouncements(): void {
		$time = time();
		if ($this->nextAnnouncement > $time) {
			return;
		}
		foreach ($this->announcements as $announcement) {
			if ($announcement->active === false) {
				continue;
			}
			$next = $announcement->getNextAnnouncement();
			if (!isset($next) || $next[0] > $time) {
				continue;
			}
			$channelName = $announcement->channels[$next[1]]->channel;
			$this->logger->info("Announcing {$announcement->name} in {$channelName}");
			$this->chatBot->send_group($channelName, $announcement->content);
			if ($next[1] === 0) {
				$announcement->last_announcement = $time;
				$this->db->update(self::DB_TABLE, "id", $announcement);
			}
		}
		$this->setNextAnnouncement();
	}

	/** Load all announcements into memory and determine when the next announcement is due */
	protected function loadAnnouncements(): void {
		$this->announcements = $this->getAnnouncements(true);
		$this->setNextAnnouncement();
	}

	/** Set the internal timestamp when a new announcement is due */
	protected function setNextAnnouncement(): void {
		$this->nextAnnouncement = null;
		foreach ($this->announcements as $announcement) {
			if ($announcement->active === false) {
				continue;
			}
			$next = $announcement->getNextAnnouncement();
			if (!isset($next)) {
				continue;
			}
			if (!isset($this->nextAnnouncement)) {
				$this->nextAnnouncement = $next[0];
			} else {
				$this->nextAnnouncement = min($this->nextAnnouncement, $next[0]);
			}
		}
	}

	/**
	 * Get all announcements and their channels
	 * @return Announcement[]
	 */
	public function getAnnouncements(bool $enabledOnly=false): array {
		$query = $this->db->table(self::DB_TABLE);
		if ($enabledOnly) {
			$query->where("active", true);
		}
		/** @var array<int,Announcement> */
		$tmp = $query->asObj(Announcement::class)
			->keyBy("id")
			->toArray();
		/** @var Collection<AnnouncementChannel> */
		$channels = $this->db->table(self::DB_TABLE_CHANNEL)
			->orderBy("announcement_id")
			->orderBy("id")
			->asObj(AnnouncementChannel::class);
		foreach ($channels as $channel) {
			if (isset($tmp[$channel->announcement_id])) {
				$tmp[$channel->announcement_id]->channels []= $channel;
			}
		}
		return array_values($tmp);
	}

	public function getAnnouncement(int $id): ?Announcement {
		/** @var Announcement|null */
		$announcement = $this->db->table(self::DB_TABLE)
			->where("id", $id)
			->asObj(Announcement::class)
			->first();
		if (!isset($announcement)) {
			return null;
		}
		$announcement->channels = $this->db->table(self::DB_TABLE_CHANNEL)
			->where("announcement_id", $announcement->id)
			->orderBy("id")
			->asObj(AnnouncementChannel::class)
			->toArray();
		return $announcement;
	}

	/**
	 * Get a list of announcements
	 */
	#[NCA\HandlesCommand("announcement")]
	public function announcementListCommand(CmdContext $context): void {
		/** @var Collection<Announcement> */
		$announcements = $this->db->table(self::DB_TABLE)
			->asObj(Announcement::class);
		if ($announcements->isEmpty()) {
			$msg = "You currently have no announcements defined. ".
				"Create a new one with <highlight><symbol>announcement create &lt;name&gt;<end>";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Stored announcements<end>\n";
		foreach ($announcements as $announcement) {
			$status = ($announcement->active ? "" : " (<red>disabled<end>)");
			$removeLink = $this->text->makeChatcmd(
				"remove",
				"/tell <myname> announcement rem {$announcement->id}"
			);
			$detailsLink = $this->text->makeChatcmd(
				"details",
				"/tell <myname> announcement view {$announcement->id}"
			);
			$previewLink = $this->text->makeChatcmd(
				"preview",
				"/tell <myname> announcement preview {$announcement->id}"
			);
			$blob .= "<tab>#{$announcement->id}: <highlight>{$announcement->name}<end>{$status} ".
				"[{$detailsLink}] [{$previewLink}] [{$removeLink}]\n";
		}
		$count = count($announcements);
		$msg = $this->text->makeBlob("Announcements ({$count})", $blob);
		$context->reply($msg);
	}

	/**
	 * View all details of an announcement
	 */
	#[NCA\HandlesCommand("announcement")]
	public function announcementShowCommand(
		CmdContext $context,
		#[NCA\Str("show", "view")] string $action,
		int $id
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$statusLink = $this->text->makeChatcmd(
			$announcement->active ? "disable" : "enable",
			"/tell <myname> announcement ".
				($announcement->active ? "disable" : "enable").
				" {$announcement->id}"
		);
		$chooseChannelsLink = $this->text->makeChatcmd(
			"choose",
			"/tell <myname> announcement channels {$announcement->id}"
		);
		$removeLink = $this->text->makeChatcmd(
			"remove",
			"/tell <myname> announcement rem {$announcement->id}"
		);
		$previewLink = $this->text->makeChatcmd(
			"preview",
			"/tell <myname> announcement preview {$announcement->id}"
		);
		$nextAnnouncement = $announcement->getNextAnnouncement();
		if (isset($nextAnnouncement)) {
			$nextAnnouncement = $this->util->date($nextAnnouncement[0]);
		} else {
			$nextAnnouncement = "never";
		}
		$blob = "<header2>Announcement #{$announcement->id}<end>\n".
			"<tab>Name: <highlight>{$announcement->name}<end>\n".
			"<tab>Status: ".
				($announcement->active ? "<green>enabled<end>" : "<red>disabled<end>").
				" [{$statusLink}]\n".
			"<tab>Created by <highlight>{$announcement->created_by}<end>\n".
			"<tab>Created on <highlight>".
				$this->util->date($announcement->created_on).
				"<end>\n".
			"<tab>Next announcement: <highlight>{$nextAnnouncement}<end>\n".
			"<tab>Channels:";
		$waitString = "\n<tab><tab>    <i>wait for ".
					$this->util->unixtimeToReadable($announcement->interval_between_channels).
					"</i>\n";
		$channels = [];
		foreach ($announcement->channels as $channel) {
			$channels []= "<tab><tab>- <highlight>{$channel->channel}<end>";
		}
		if (empty($channels)) {
			$blob .= " <highlight>None<end> [{$chooseChannelsLink}]\n";
		} else {
			$blob .= " [{$chooseChannelsLink}]\n" . join($waitString, $channels) . "\n";
		}
		$blob .= "<tab>Pause between announcements: <highlight>".
			$this->util->unixtimeToReadable($announcement->interval_between_announcements).
			"<end>\n".
			"<tab>Actions: [{$previewLink}] [{$removeLink}]";
		$msg = $this->text->makeBlob("Announcement '{$announcement->name}'", $blob);
		$context->reply($msg);
	}

	/**
	 * Create a new announcement
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	#[NCA\Help\Prologue(
		"Announcements are periodic messages sent to one or more public channels.\n".
		"They are disabled by default, so you can plan them before running."
	)]
	public function announcementNewCommand(
		CmdContext $context,
		#[NCA\Str("create", "new", "add")] string $action,
		string $name
	): void {
		$announcement = new Announcement();
		$announcement->created_by = $context->char->name;
		$announcement->name = $name;
		$announcement->content = "TBD";

		$announcement->id = $this->db->insert(self::DB_TABLE, $announcement);
		$msg = "Announcement <highlight>{$announcement->name}<end> created ".
			"successfully as <highlight>#{$announcement->id}<end>.";
		$context->reply($msg);
		$this->loadAnnouncements();
	}

	/**
	 * Set the content of an announcement. Can be either an HTML string, or a file
	 * in your data directory.
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementSetContentCommand(
		CmdContext $context,
		#[NCA\Str("content")] string $action,
		int $id,
		string $content
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$content = str_replace(["&amp;", "&lt;", "&gt;"], ["&", "<", ">"], $content);
		$dataPath = $this->config->dataFolder;
		if (@file_exists("{$dataPath}/{$content}")) {
			$content = file_get_contents("{$dataPath}/{$content}");
		}
		$announcement->content = $content;
		if (!$this->db->update(self::DB_TABLE, "id", $announcement)) {
			$context->reply("There was an error saving your new content");
			return;
		}
		$this->loadAnnouncements();
		$msg = "New content set: {$content}";
		$context->reply($msg);
	}

	/**
	 * Preview what an announcement would look like
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementPreviewCommand(
		CmdContext $context,
		#[NCA\Str("preview")] string $action,
		int $id,
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$context->reply($announcement->content);
	}

	/**
	 * Select which channels to announce to
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementChannelsCommand(
		CmdContext $context,
		#[NCA\Str("channels")] string $action,
		int $id,
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$channels = $this->getAllWritableChannels();
		if (!count($channels)) {
			$msg = "The bot is currently not listening to any channels it can write to. ".
				"Please log into the account and enable the channels you want to be able ".
				"to send messages to.";
			$context->reply($msg);
			return;
		}
		$channelNames = array_column(
			$announcement->channels,
			"channel"
		);
		$blob = "<header2>Available channels<end>\n";
		foreach ($channels as $channel) {
			if (in_array($channel->name, $channelNames)) {
				$link = $this->text->makeChatcmd(
					"exclude",
					"/tell <myname> announcement channels {$id} exclude {$channel->id}"
				);
			} else {
				$link = $this->text->makeChatcmd(
					"include",
					"/tell <myname> announcement channels {$id} include {$channel->id}"
				);
			}
			$blob .= "<tab>[{$link}] {$channel->name} ({$channel->id})\n";
		}
		$msg = $this->text->makeBlob("Choose the channel(s) to announce to", $blob);
		$context->reply($msg);
	}

	/**
	 * Add or remove a channel to announce to
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementSelectChannelCommand(
		CmdContext $context,
		#[NCA\Str("channels")] string $action,
		int $id,
		#[NCA\StrChoice("include", "exclude")] string $subAction,
		int $channelId,
	): void {
		$subAction = strtolower($subAction);
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$channels = $this->getAllWritableChannels();
		$channel = null;
		foreach ($channels as $channelItem) {
			if ($channelItem->id === $channelId) {
				$channel = $channelItem;
			}
		}
		if (!isset($channel)) {
			$msg = "Unable to find the channel with id <highlight>{$channelId}<end>.";
			$context->reply($msg);
			return;
		}
		if ($action === "exclude") {
			$context->reply($this->unsubscribeChannel($announcement, $channel));
			return;
		}
		$context->reply($this->subscribeChannel($announcement, $channel));
	}

	/** Disable channel $channel on your announcement $announcement */
	protected function unsubscribeChannel(Announcement $announcement, Channel $channel): string {
		$enabledChannelNames = array_column(
			$announcement->channels,
			"channel"
		);
		if (!in_array($channel->name, $enabledChannelNames)) {
			return "You are currently not announcing into <highlight>{$channel->name}<end>.";
		}
		$this->db->table(self::DB_TABLE_CHANNEL)
			->where("channel", $channel->name)
			->delete();
		return "No longer announcing into <highlight>{$channel->name}<end>.";
	}

	/** Enable channel $channel on your announcement $announcement */
	protected function subscribeChannel(Announcement $announcement, Channel $channel): string {
		$enabledChannelNames = array_column(
			$announcement->channels,
			"channel"
		);
		if (in_array($channel->name, $enabledChannelNames)) {
			return "You are already announcing into <highlight>{$channel->name}<end>.";
		}
		$newChannel = new AnnouncementChannel();
		$newChannel->announcement_id = $announcement->id;
		$newChannel->channel = $channel->name;
		$this->db->insert(self::DB_TABLE_CHANNEL, $newChannel);
		$this->loadAnnouncements();
		return "Now also announcing into <highlight>{$channel->name}<end>.";
	}

	/**
	 * Change the announce interval
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	#[NCA\Help\Example("<symbol>announcement interval 1 1h")]
	public function announcementSetIntervalCommand(
		CmdContext $context,
		#[NCA\Str("interval")] string $action,
		int $id,
		PDuration $interval,
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$intervalSecs = $interval->toSecs();
		if ($intervalSecs === 0) {
			$msg = "<highlight>{$interval}<end> is not a valid interval.";
			$context->reply($msg);
			return;
		}
		$announcement->interval_between_announcements = $intervalSecs;
		$this->db->update(self::DB_TABLE, "id", $announcement);
		$niceInterval = $this->util->unixtimeToReadable($intervalSecs);
		$msg = "Interval of announcements for <highlight>{$announcement->name}<end> ".
			"set to <highlight>{$niceInterval}<end>.";
		$this->loadAnnouncements();
		$context->reply($msg);
	}

	/**
	 * Change the delay between sending to 2 channels
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	#[NCA\Help\Example("<symbol>announcement channeldelay 1 10s")]
	public function announcementSetChannelDelayCommand(
		CmdContext $context,
		#[NCA\Str("channeldelay")] string $action,
		int $id,
		PDuration $delay,
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$delaySecs = $delay->toSecs();
		if ($delaySecs === 0) {
			$msg = "<highlight>{$delay}<end> is not a valid delay.";
			$context->reply($msg);
			return;
		}
		$announcement->interval_between_channels = $delaySecs;
		$this->db->update(self::DB_TABLE, "id", $announcement);
		$niceDelay = $this->util->unixtimeToReadable($delaySecs);
		$msg = "Delay between announcements on different channels for ".
			"<highlight>{$announcement->name}<end> set to <highlight>{$niceDelay}<end>.";
		$this->loadAnnouncements();
		$context->reply($msg);
	}

	/**
	 * Enable or disable an announcement
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementSetStatusCommand(CmdContext $context, bool $enable, int $id): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$announcement->active = $enable;
		$this->db->update(self::DB_TABLE, "id", $announcement);
		if ($enable) {
			$msg = "Announcement <highlight>#{$announcement->id}<end> is now <green>enabled<end>.";
		} else {
			$msg = "Announcement <highlight>#{$announcement->id}<end> is now <red>disabled<end>.";
		}
		$this->loadAnnouncements();
		$context->reply($msg);
	}

	/**
	 * Rename an announcement
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementRenameCommand(
		CmdContext $context,
		#[NCA\Str("rename")] string $action,
		int $id,
		string $newName
	): void {
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$announcement->name = $newName;
		$this->db->update(self::DB_TABLE, "id", $announcement);
		$msg = "Name of announcement <highlight>#{$announcement->id}<end> is now ".
			"<highlight>{$announcement->name}<end>.";
		$this->loadAnnouncements();
		$context->reply($msg);
	}

	/**
	 * Delete an announcement
	 */
	#[NCA\HandlesCommand(self::CMD_ANNOUNCEMENT_ADMIN)]
	public function announcementRemove(CmdContext $context, PRemove $action, int $id): void {
		if (!$this->db->table(self::DB_TABLE)->delete($id)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$context->reply($msg);
			return;
		}
		$this->db->table(self::DB_TABLE_CHANNEL)
			->where("announcement_id", $id)
			->delete();
		$msg = "Announcement <highlight>#{$id}<end> deleted.";
		$this->loadAnnouncements();
		$context->reply($msg);
	}

	/**
	 * Get a list of all channels available to the bot to write in
	 * @return Channel[]
	 */
	public function getAllWritableChannels(): array {
		/** @var Channel[] */
		$channels = [];
		foreach ($this->chatBot->grp as $gid => $status) {
			$b = unpack("Ctype/Nid", (string)$gid);
			$channel = new Channel();
			$channel->id = $b["id"];
			$channel->type = $b["type"];
			$channel->name = (string)$this->chatBot->gid[$gid];
			if ($channel->isReadOnly()) {
				continue;
			}
			$channels []= $channel;
		}
		usort(
			$channels,
			function(Channel $ch1, Channel $ch2): int {
				return ($ch1->type <=> $ch2->type)
					?: strcasecmp($ch1->name, $ch2->name);
			}
		);
		return $channels;
	}
}
