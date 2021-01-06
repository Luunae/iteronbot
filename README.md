# Iteronbot

Imagine writing a good readme

## Requirements

* PHP (tested on 8.0, but i *think* 7.3+ should be fine)
* Composer
* Your relational database of choice (tested on mysql8 but highkey anything should work)

## Installation

* `git clone https://github.com/sylae/iteronbot`
* copy `config.sample.php` to `config.php`, and edit in your values
    * discord bot token
    * database deets
    * Janice API key
    * Bot admin IDs (note: this will grant them `!eval` access, among other things
      (most notably `!update` and `!restart` to allow updating the repo without ssh'ing in))
      
To run the bot, simply execute `./huntress`. I recommend running it in tmux or
screen or something.

## Commands

### User

* `!request` - create an EveTrader request
* `!requests` (alias: `!orders`) - show all requests that have been unfulfilled.
* `!claim` - claim a request
* `!complete` - mark a request as having been fulfilled

### Administration

* `!eval` - execute arbitary code
* `!restart` - restart the bot (most useful after running...)
* `!update` - runs the `./update` script (pulls from repo, runs composer, etc)
* `!ping` - Pong.
* `!huntress` - Bot statistics and information

## Extending

see [sylae/huntress](https://github.com/sylae/huntress) (in particular the `keira` branch)
for examples on how to extend and add functionality via more plugins.
