<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ANNOUNCE_MODULE;

class Channel {
	public const TYPE_ORG = 3;
	public const TYPE_PINK = 10;
	public const TYPE_RED = 12;
	public const TYPE_SHOPPING = 134;
	public const TYPE_OOC = 135;

	public int $type;
	public int $id;
	public string $name;

	/** Check if a channel is read only, i.e. we cannot send messages to */
	public function isReadOnly(): bool {
		return in_array($this->type, [self::TYPE_PINK, self::TYPE_RED]);
	}
}
