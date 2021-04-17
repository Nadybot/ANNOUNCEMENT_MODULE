# ANNOUNCEMENT_MODULE

A Nadybot module to periodically send messages to one or more public channels. For announcing new bots, new features or simply recruitment.

## Usage

The `1` in these examples refers to the announcement number that you will see once you create it.

* `!announcement create recruitment` to create one
* `!announcement content 1 Join Nadybot testers now, send a tell to Nady!` to define the message body. You can either paste AOML here, or give the filename inside `data` that contains the actual message
* `!announcement channels 1` to choose which channels to announce to
* `!announcement interval 1 1h` to send the message once every hour
* `!announcement channeldelay 1 5s` to wait 5s before sending to another channel
* `!announcement view 1` to peek at all parameters
* `!announcement preview 1` to see what your message would look like
* `!announcement enable 1` to go live!
* `!announcements` to see all your anouncements

## Notice

Keep in mind that the bot can only message to people in their zone or linked zones. So if your bot logged out in ICC, then only people in ICC will get the message.
If your bot logged out in Newland, then only people in Newland or Borealis will be able to see it and so on.
