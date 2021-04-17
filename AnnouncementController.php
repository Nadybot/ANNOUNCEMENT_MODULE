<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ANNOUNCE_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	LoggerWrapper,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'announcement',
 *		accessLevel = 'member',
 *		description = 'See a list of all announcements',
 *		alias       = 'announcements',
 *		help        = 'announcement.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'announcement .+',
 *		accessLevel = 'mod',
 *		description = 'Modify the announcements',
 *		help        = 'announcement.txt'
 *	)
 */
class AnnouncementController {

	public const TYPE_TOWER = 10;
	public const TYPE_TOUR = 12;
	public const TYPE_SHOPPING = 134;
	public const TYPE_OOC = 135;

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Logger */
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

	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "announcement");
		$this->loadAnnouncements();
	}

	/**
	 * @Event("timer(1sec)")
	 * @Description("Send out announcements")
	 */
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
			$this->logger->log('INFO', "Announcing {$announcement->name} in {$channelName}");
			$this->chatBot->send_group($channelName, $announcement->content);
			if ($next[1] === 0) {
				$announcement->last_announcement = $time;
				$this->db->update("announcement_<myname>", "id", $announcement);
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
	 * Get all annoncements and their channels
	 * @return Announcement[]
	 */
	public function getAnnouncements(bool $enabledOnly=false): array {
		$params = $enabledOnly ? [true] : [];
		/** @var Announcement[] */
		$announcements = $this->db->fetchAll(
			Announcement::class,
			"SELECT * FROM `announcement_<myname>`".
			($enabledOnly ? " WHERE active=?" : ""),
			...$params
		);
		/** @var array<int,Announcement> */
		$tmp = [];
		foreach ($announcements as $announcement) {
			$tmp[$announcement->id] = $announcement;
		}
		/** @var AnnouncementChannel[] */
		$channels = $this->db->fetchAll(
			AnnouncementChannel::class,
			"SELECT * FROM `announcement_channel_<myname>` ".
			"ORDER BY `announcement_id` ASC, `id` ASC",
		);
		foreach ($channels as $channel) {
			if (isset($tmp[$channel->announcement_id])) {
				$tmp[$channel->announcement_id]->channels []= $channel;
			}
		}
		return array_values($tmp);
	}

	public function getAnnouncement(int $id): ?Announcement {
		/** @var Announcement|null */
		$announcement = $this->db->fetch(
			Announcement::class,
			"SELECT * FROM `announcement_<myname>` WHERE `id`=?",
			$id
		);
		if (!isset($announcement)) {
			return null;
		}
		$announcement->channels = $this->db->fetchAll(
			AnnouncementChannel::class,
			"SELECT * FROM `announcement_channel_<myname>` ".
			"WHERE `announcement_id`=? ORDER BY `id` ASC",
			$announcement->id
		);
		return $announcement;
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement (new|create|add) (?<name>.+)$/i")
	 */
	public function announcementNewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$announcement = new Announcement();
		$announcement->created_by = $sender;
		$announcement->name = $args['name'];
		$announcement->content = "TBD";

		$announcement->id = $this->db->insert("announcement_<myname>", $announcement);
		$msg = "Announcement <highlight>{$announcement->name}<end> created ".
			"successfully as <highlight>#{$announcement->id}<end>.";
		$sendto->reply($msg);
		$this->loadAnnouncements();
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement content (?<id>\d+) (?<content>.+)$/is")
	 */
	public function announcementSetContentCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$content = str_replace(["&amp;", "&lt;", "&gt;"], ["&", "<", ">"], $args['content']);
		$dataPath = $this->chatBot->vars["datafolder"]??"./data";
		if (@file_exists("{$dataPath}/{$content}")) {
			$content = file_get_contents("{$dataPath}/{$content}");
		}
		$announcement->content = $content;
		if (!$this->db->update("announcement_<myname>", "id", $announcement)) {
			$sendto->reply("There was an error saving your new content");
			return;
		}
		$this->loadAnnouncements();
		$msg = "New content set: {$content}";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement preview (?<id>\d+)$/i")
	 */
	public function announcementPreviewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$sendto->reply($announcement->content);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement channels (?<id>\d+)$/i")
	 */
	public function announcementChannelsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$channels = $this->getAllWritableChannels();
		if (!count($channels)) {
			$msg = "The bot is currently not listening to any channels it can write to. ".
				"Please log into the account and enable the channels you want to be able ".
				"to send messages to.";
			$sendto->reply($msg);
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
					"Exclude",
					"/tell <myname> announcement channels {$id} exclude {$channel->id}"
				);
			} else {
				$link = $this->text->makeChatcmd(
					"Include",
					"/tell <myname> announcement channels {$id} include {$channel->id}"
				);
			}
			$blob .= "<tab>[{$link}] {$channel->name} ({$channel->id})\n";
		}
		$msg = $this->text->makeBlob("Choose the channel(s) to announce to", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement channels (?<id>\d+) (?<action>include|exclude) (?<channel>\d+)$/i")
	 */
	public function announcementSelectChannelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$channelId = (int)$args['channel'];
		$action = strtolower($args['action']);
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
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
			$sendto->reply($msg);
			return;
		}
		if ($action === "exclude") {
			$sendto->reply($this->unsubscribeChannel($announcement, $channel));
			return;
		}
		$sendto->reply($this->subscribeChannel($announcement, $channel));
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
		$this->db->exec(
			"DELETE FROM `announcement_channel_<myname>` WHERE `channel`=?",
			$channel->name
		);
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
		$this->db->insert("announcement_channel_<myname>", $newChannel);
		$this->loadAnnouncements();
		return "Now also announcing into <highlight>{$channel->name}<end>.";
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement interval (?<id>\d+) (?<interval>.+)$/i")
	 */
	public function announcementSetIntervalCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$interval = $this->util->parseTime($args['interval']);
		if ($interval === 0) {
			$msg = "<highlight>{$args['interval']}<end> is not a valid interval.";
			$sendto->reply($msg);
			return;
		}
		$announcement->interval_between_announcements = $interval;
		$this->db->update("announcement_<myname>", "id", $announcement);
		$niceInterval = $this->util->unixtimeToReadable($interval);
		$msg = "Interval of announcements for <highlight>{$announcement->name}<end> ".
			"set to <highlight>{$niceInterval}<end>.";
		$this->loadAnnouncements();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement channeldelay (?<id>\d+) (?<delay>.+)$/i")
	 */
	public function announcementSetChannelDelayCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$delay = $this->util->parseTime($args['delay']);
		if ($delay === 0) {
			$msg = "<highlight>{$args['delay']}<end> is not a valid delay.";
			$sendto->reply($msg);
			return;
		}
		$announcement->interval_between_channels = $delay;
		$this->db->update("announcement_<myname>", "id", $announcement);
		$niceDelay = $this->util->unixtimeToReadable($delay);
		$msg = "Delay between announcements on different channels for ".
			"<highlight>{$announcement->name}<end> set to <highlight>{$niceDelay}<end>.";
		$this->loadAnnouncements();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement (?<action>enable|disable) (?<id>\d+)$/i")
	 */
	public function announcementSetStatusCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$action = strtolower($args['action']);
		$announcement->active = $action === 'enable';
		$this->db->update("announcement_<myname>", "id", $announcement);
		if ($action === 'enable') {
			$msg = "Announcement <highlight>#{$announcement->id}<end> is now <green>enabled<end>.";
		} else {
			$msg = "Announcement <highlight>#{$announcement->id}<end> is now <red>disabled<end>.";
		}
		$this->loadAnnouncements();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement rename (?<id>\d+) (?<name>.+)$/i")
	 */
	public function announcementRenameCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$announcement->name = $args['name'];
		$this->db->update("announcement_<myname>", "id", $announcement);
		$msg = "Name of announcement <highlight>#{$announcement->id}<end> is now ".
			"<highlight>{$announcement->name}<end>.";
		$this->loadAnnouncements();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement (?:rem|del|remove|delete|rm) (?<id>\d+)$/i")
	 */
	public function announcementRemove(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		if (!$this->db->exec("DELETE FROM `announcement_<myname>` WHERE `id`=?", $id)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$this->db->exec("DELETE FROM `announcement_channel_<myname>` WHERE `announcement_id`=?", $id);
		$msg = "Announcement <highlight>#{$id}<end> deleted.";
		$this->loadAnnouncements();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement .+")
	 * @Matches("/^announcement (?:show|view) (?<id>\d+)$/i")
	 */
	public function announcementShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args['id'];
		$announcement = $this->getAnnouncement($id);
		if (!isset($announcement)) {
			$msg = "No announcement <highlight>#{$id}<end> found.";
			$sendto->reply($msg);
			return;
		}
		$statusLink = $this->text->makeChatcmd(
			$announcement->active ? "Disable" : "Enable",
			"/tell <myname> announcement ".
				($announcement->active ? "disable" : "enable").
				" {$announcement->id}"
		);
		$chooseChannelsLink = $this->text->makeChatcmd(
			"Choose",
			"/tell <myname> announcement channels {$announcement->id}"
		);
		$removeLink = $this->text->makeChatcmd(
			"Remove",
			"/tell <myname> announcement rem {$announcement->id}"
		);
		$previewLink = $this->text->makeChatcmd(
			"Preview",
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("announcement")
	 * @Matches("/^announcement$/i")
	 */
	public function announcementListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Announcement[] */
		$announcements = $this->db->fetchAll(
			Announcement::class,
			"SELECT * FROM `announcement_<myname>`"
		);
		if (empty($announcements)) {
			$msg = "You currently have no announcements defined. ".
				"Create a new one with <highlight><symbol>announcement create 'name'<end>";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Stored announcements<end>\n";
		foreach ($announcements as $announcement) {
			$status = ($announcement->active ? "" : " (<red>disabled<end>)");
			$removeLink = $this->text->makeChatcmd(
				"Remove",
				"/tell <myname> announcement rem {$announcement->id}"
			);
			$detailsLink = $this->text->makeChatcmd(
				"Details",
				"/tell <myname> announcement view {$announcement->id}"
			);
			$previewLink = $this->text->makeChatcmd(
				"Preview",
				"/tell <myname> announcement preview {$announcement->id}"
			);
			$blob .= "<tab>#{$announcement->id}: <highlight>{$announcement->name}<end>{$status} ".
				"[{$detailsLink}] [{$previewLink}] [{$removeLink}]\n";
		}
		$count = count($announcements);
		$msg = $this->text->makeBlob("Announcements ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Get a list of all channels available to the bot to write in
	 * @return Channel[]
	 */
	public function getAllWritableChannels(): array {
		/** @var Channel[] */
		$channels = [];
		foreach ($this->chatBot->grp as $gid => $status) {
			$b = unpack("Ctype/Nid", $gid);
			$channel = new Channel();
			$channel->id = $b["id"];
			$channel->type = $b["type"];
			$channel->name = $this->chatBot->gid[$gid];
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
