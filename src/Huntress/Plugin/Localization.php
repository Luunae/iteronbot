<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use Carbon\CarbonTimeZone;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\GuildMemberStorage;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\UserLocale;
use React\Promise\PromiseInterface as Promise;
use Throwable;

/**
 * Internationalization and timezone nonsense.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Localization implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, 'db'])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("time")
            ->setCallback([self::class, "timeHelper"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("timezone")
            ->setCallback([self::class, "timezone"])
        );
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("locale");
        $t->addColumn("user", "bigint", ["unsigned" => true]);
        $t->addColumn("timezone", "text",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->addColumn("locale", "text",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["user"]);
    }

    public static function timezone(EventData $data): ?Promise
    {
        try {
            $args = self::_split($data->message->content);
            $now = Carbon::now();
            if (count($args) > 1) {
                try {
                    $zone = new CarbonTimeZone($args[1]);
                    $zone = self::normalizeTZName($zone);
                } catch (\Throwable $e) {
                    return self::error($data->message, "Unknown Timezone",
                        "I couldn't understand that. Please pick a value from [this list](https://www.php.net/manual/en/timezones.php).");
                }
                $query = DatabaseFactory::get()->prepare('INSERT INTO locale (user, timezone) VALUES(?, ?) '
                    . 'ON DUPLICATE KEY UPDATE timezone=VALUES(timezone);', ['integer', 'string']);
                $query->bindValue(1, $data->message->author->id);
                $query->bindValue(2, $zone->getName());
                $query->execute();
                $string = "Your timezone has been updated to **%s**.\nI have your local time as **%s**\n\nIf this was incorrect, please use one of the values in <https://www.php.net/manual/en/timezones.php>.\n*Note:* In most cases you should use the Continent/City values, as they will automatically compensate for Daylight Savings for your region.";
            } else {
                $string = "Your timezone is currently set to **%s**.\nI have your local time as **%s**\n\nTo update, run `!timezone NewTimeZone` with one of the values in <https://www.php.net/manual/en/timezones.php>.\n*Note:* In most cases you should use the Continent/City values, as they will automatically compensate for Daylight Savings for your region.";
            }
            $tz = new UserLocale($data->message->author);
            $now_tz = $tz->applyTimezone($now);
            return self::send($data->message->channel, sprintf($string, $tz->timezone ?? "<unset (default UTC)>",
                $tz->localeSandbox(function () use ($now_tz) {
                    return $now_tz->toDayDateTimeString();
                })));
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    private static function normalizeTZName(CarbonTimeZone $in): CarbonTimeZone
    {
        $zones = (new Collection($in::listIdentifiers($in::ALL_WITH_BC)))
            ->filter(function ($v) use ($in) {
                return mb_strtolower($in->toRegionName()) == mb_strtolower($v);
            });

        if ($zones->count() == 0) {
            // nothing matched but we got something, it's probably just an offset?
            return $in;
        } else {
            return new CarbonTimeZone($zones->first());
        }
    }

    public static function timeHelper(EventData $data): ?Promise
    {
        $time = self::arg_substr($data->message->content, 1);
        $warn = [];
        // get the user's locale first
        $user_tz = self::fetchTimezone($data->message->member);
        if (is_null($user_tz)) {
            $warn[] = "Note: Your timezone is unset, assuming UTC. Please use `!timezone` to tell me your timezone.";
            $user_tz = "UTC";
        }

        // get origininal time
        try {
            $time = trim($time);
            $time = self::readTime($time, $user_tz);
        } catch (\Throwable $e) {
            return $data->message->channel->send("I couldn't figure out what time `$time` is :(");
        }

        // grab everyone's zone
        $tzs = self::fetchTimezones(self::getMembersWithPermission($data->channel));

        $tzs = array_map(function ($v) {
            try {
                return new CarbonTimeZone($v);
            } catch (\Throwable $e) {
                return null;
            }
        }, $tzs);

        uasort($tzs, function (?CarbonTimeZone $a, ?CarbonTimeZone $b) use ($time) {
            if (is_null($a) || is_null($b)) {
                return 0;
            }
            $off = $a->getOffset($time) <=> $b->getOffset($time);
            $loc = $a->getLocation() <=> $b->getLocation();

            if ($off === 0) {
                return $loc;
            }
            return $off;
        });

        $lines = [];
        foreach ($tzs as $tz) {
            if (is_null($tz)) {
                continue;
            }
            $ntime = clone $time;
            $ntime->setTimezone($tz);
            $lines[] = sprintf("**%s**: %s", $tz, $ntime->toDayDateTimeString());
        }
        $lines = implode(PHP_EOL, $lines);

        $embed = new MessageEmbed();

        $tzinfo = sprintf("%s (%s)", $time->getTimezone()->toRegionName(), $time->getTimezone()->toOffsetName());
        $embed->addField("Detected Time",
            $time->toDayDateTimeString() . PHP_EOL . $tzinfo . PHP_EOL . $time->longRelativeToNowDiffForHumans(2));

        $times = MessageHelpers::splitMessage($lines,
            ['maxLength' => 1024]);
        $firstTime = true;
        foreach ($times as $tblock) {
            $embed->addField($firstTime ? "Times" : "Times (cont.)", $tblock);
            $firstTime = false;
        }

        $embed->setTitle("Translated times for users in channel");
        $embed->setDescription("Don't see your timezone? Use the `!timezone` command.");

        return $data->message->channel->send(implode(PHP_EOL, $warn) ?? "", ['embed' => $embed]);
    }

    public static function fetchTimezone(GuildMember $member): ?string
    {
        $res = DatabaseFactory::get()->executeQuery('SELECT * FROM locale WHERE user=?',
            [$member->id], ["integer"])->fetchAll();
        foreach ($res as $row) {
            return $row['timezone'] ?? null;
        }
        return null;
    }

    public static function fetchTimezones(GuildMemberStorage $members): array
    {
        $res = DatabaseFactory::get()->executeQuery('SELECT * FROM locale WHERE user IN (?)',
            [$members->pluck("id")->all()], [Connection::PARAM_INT_ARRAY])->fetchAll();
        return array_unique(array_column($res, "timezone"));
    }
}
