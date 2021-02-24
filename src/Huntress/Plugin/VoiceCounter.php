<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\VoiceChannel;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;

class VoiceCounter implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->setCallback([self::class, "updateCount"])
            ->setPeriodic(60)
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("voicestats")
            ->addGuild(EveTrader::GUILD)
            ->setCallback([self::class, "statsHandler"])
        );
    }

    public static function statsHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (EveTrader::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            $files = [];
            foreach (self::getGraphs($data->huntress) as $k => $d) {
                $files[] = ['name' => "bnyse_voiceactivity_$k.png", 'data' => $d];
            }

            return $data->message->channel->send("", ['files' => $files]);

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    public static function updateCount(Huntress $bot): ?PromiseInterface
    {
        // create rrd if it doesnt exist...
        self::createRRD($bot);

        // get total count
        $count = $bot->guilds->get(EveTrader::GUILD)->channels->filter(fn ($v) => $v instanceof VoiceChannel)->reduce(function (int $count, VoiceChannel $v) {
            if ($v->id == 662008034205368351) return $count;
            $count += $v->members->count();
            return $count;
        }, 0);

        self::updateRRD($bot, $count);

        return null;
    }

    public static function updateRRD(Huntress $bot, int $count): void {
        $exe = (PHP_OS == "WINNT") ? "wsl rrdtool" : "rrdtool";
        $command = "$exe update temp/voice_activity_bnyse.rrd N:$count";
        $return = `$command`;
        $bot->log->info($return);

    }

    public static function createRRD(Huntress $bot, bool $force = false): void {
        $exe = (PHP_OS == "WINNT") ? "wsl rrdtool" : "rrdtool";

        // DS:name:type:timeout:min:max
        // RRA:method:xff:lastx:rows
        $command = "$exe create temp/voice_activity_bnyse.rrd --step 60 ".
            "DS:users:GAUGE:120:0:512 ".
            "RRA:AVERAGE:0.5:1:1440 ". // 1 day @ 1 minute resolution
            "RRA:AVERAGE:0.5:15:672 " . // 1 week @ 15 minute resolution
            "RRA:AVERAGE:0.5:60:720 " . // 30 days @ 1 hour resolution
            "RRA:AVERAGE:0.5:1440:365"; // 365 days @ 1 day resolution

        if (!file_exists("temp/voice_activity_bnyse.rrd") || $force) {
            $bot->log->info("Creating BNYSE voice chat activity logger file...");
            $return = `$command`;
            $bot->log->info($return);
        }
    }

    public static function getGraphs(Huntress $bot): array {
        $sizes = [
            'day' => '1d',
            'week' => '1w',
            'month' => '1m',
            'year' => '1y',
        ];

        $files = [];
        $exe = (PHP_OS == "WINNT") ? "wsl TZ=UTC rrdtool" : " TZ=UTC rrdtool";
        foreach ($sizes as $k => $v) {
            $command = "$exe graph - ".
                "--start end-$v ".
                "--imgformat PNG ".
                "--title \"BNYSE Voice channel activity\" ".
                "--vertical-label \"Users connected\" ".
                "--width 480 ".
                "--height 160 ".
                "--watermark \"Timezone: UTC\" ".
                "--use-nan-for-all-missing-data ".
                "--lower-limit 0 ".
                "DEF:connected=temp/voice_activity_bnyse.rrd:users:AVERAGE ".
                "LINE2:connected#FF0000";
            $files[$k] = `$command`;
        }
        return $files;
    }

}
