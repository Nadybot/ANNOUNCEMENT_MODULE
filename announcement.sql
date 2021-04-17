CREATE TABLE IF NOT EXISTS `announcement_<myname>`(
	`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
	`name` VARCHAR(100) NOT NULL,
	`content` TEXT NOT NULL DEFAULT FALSE,
	`active` BOOLEAN NOT NULL,
	`created_by` VARCHAR(15) NOT NULL,
	`created_on` INT NOT NULL,
	`interval_between_channels` INT NOT NULL DEFAULT 5,
	`interval_between_announcements` INT NOT NULL DEFAULT 1800,
	`last_announcement` INT
);

CREATE TABLE IF NOT EXISTS `announcement_channel_<myname>`(
	`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
	`announcement_id` INTEGER NOT NULL,
	`channel` VARCHAR(100) NOT NULL
);