<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\Snowflake;
use Iteronbot\EvepraisalRequest;
use Iteronbot\HaulRequest;
use Iteronbot\Item;
use Iteronbot\JaniceRequest;
use React\Promise\PromiseInterface;
use ReflectionClass;
use Throwable;

class EveTrader implements PluginInterface
{
    use PluginHelperTrait;

    /*
    const GUILD = 349058708304822273; // masturbatorium - test
    const ROLES = [
        365084727750688768, // cum elemental
        606468384472825884, // interns
    ];
    const MODROLE = 365084727750688768;
    */

    const GUILD = 616779348250329128; // be nice - prod
    const ROLES = [
        616781834939793428, // director
        616781942167175198, // comrade
    ];
    const MODROLE = 616781834939793428;

    const PIN_URL = "https://canary.discord.com/channels/616779348250329128/721026603395711089/812982938668761108";

    const EVEPRAISAL = "/https?:\/\/.*?evepraisal.com\/a\/(.+)$/i";
    const JANICE = "/https?:\/\/.*?janice\.e-351\.com\/a\/(.+)$/i";

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("request")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "requestHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("requests")
            ->addCommand("requestlist")
            ->addCommand("orders")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "listHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("claim")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "claimHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("unclaim")
            ->addCommand("release")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "unclaimHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("complete")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "completeHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("haulstats")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "statsHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );

        $bot->eventManager->addEventListener(EventListener::new()->setCallback([
            self::class,
            "pinUpdate",
        ])->setPeriodic(60));

    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("evetrader");
        $t->addColumn("idTrade", "bigint", ["unsigned" => true]);
        $t->addColumn("site", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET, 'length' => 255]);
        $t->addColumn("idBuyer", "bigint", ["unsigned" => true]);
        $t->addColumn("idSeller", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t->addColumn("createTime", "datetime");
        $t->addColumn("claimTime", "datetime", ['notnull' => false]);
        $t->addColumn("completeTime", "datetime", ['notnull' => false]);
        $t->addColumn("items", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("notes", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->addColumn("siteData", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["idTrade"]);
    }

    public static function pinUpdate(Huntress $bot): ?PromiseInterface
    {
        try {
            return self::fetchMessage($bot, self::PIN_URL)->then(function ($message) {
                if ($message->author->id != $message->client->user->id) {
                    return null;
                }
                return $message->edit("", ['embed' => self::getListEmbed($message->guild)]);
            });
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function statsHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (self::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            $orders = self::getAllTrades($data->guild);


            /** @var Collection $members */
            $members = $orders->reduce(function (Collection $carry, HaulRequest $v) use ($data) {
                if (is_null($v->seller)) {
                    return $carry;
                }
                if (!$carry->has($v->seller)) {
                    $carry->set($v->seller, (object) [
                        'name' => $data->guild->members->get($v->seller)->displayName ?? "Unknown user ".$v->seller,
                        'num' => 0,
                        'isk' => 0,
                        'm3' => 0,
                    ]);
                }

                $carry->get($v->seller)->num++;
                $carry->get($v->seller)->isk += $v->getTotalPrice();
                $carry->get($v->seller)->m3 += $v->getTotalVolume();

                return $carry;
            }, new Collection())->sortCustom(function ($a, $b) {
                $isk = $a->isk <=> $b->isk;
                $vol = $a->m3 <=> $b->m3;
                $num = $a->num <=> $b->num;

                if ($vol == 0) {
                    if ($num == 0) {
                        return $isk * -1;
                    } else {
                        return $num * -1;
                    }
                } else {
                    return $vol * -1;
                }
            })->map(function ($v) {
                return [
                    'name' => $v->name,
                    'num' => number_format($v->num),
                    'isk' => number_format($v->isk),
                    'm3' => number_format($v->m3),
                ];
            });

            $heads = [
                'name' => "Hauler",
                'num' => "Reqs",
                'isk' => "Total ISK",
                'm3' => "Total m3",
            ];

            $width = [
                'name' => mb_strwidth($heads['name']),
                'num' => mb_strwidth($heads['num']),
                'isk' => mb_strwidth($heads['isk']),
                'm3' => mb_strwidth($heads['m3']),
            ];

            $width = $members->reduce(function (array $width, $v) {
                foreach ($width as $key => $value) {
                    $width[$key] = max($value, mb_strwidth($v[$key]));
                }
                return $width;
            }, $width);

            $closure = fn ($v, $k) => str_pad($v, $width[$k], " ", ($k == "name"));

            $x = [];
            $x[] = "```";
            $x[] = implode("  ", array_map($closure, $heads, array_keys($heads)));
            $x = $members->reduce(function ($x, $v) use ($closure) {
                $x[] = implode("  ", array_map($closure, $v, array_keys($v)));
                return $x;
            }, $x);
            $x[] = "```";

            return $data->message->channel->send(implode(PHP_EOL, $x),
                ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]);

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    public static function requestHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (self::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            if (count(self::_split($data->message->content)) < 2) {
                return self::error($data->message, "Unable to parse",
                    "Usage: `!request EVEPRAISALorJANICE_URL and then optionally any notes`");
            }

            $url = self::arg_substr($data->message->content, 1, 1);

            $m = [];
            if (preg_match(self::EVEPRAISAL, $url, $m)) {
                $apiID = $m[1];
                $hr = new EvepraisalRequest();
            } elseif (preg_match(self::JANICE, $url, $m)) {
                $apiID = $m[1];
                $hr = new JaniceRequest();
            } else {
                return self::error($data->message, "Unable to parse",
                    "Usage: `!request EVEPRAISALorJANICE_URL and then optionally any notes`");
            }

            $apiURL = $hr->getAPI($apiID);

            return URLHelpers::resolveURLToData($apiURL)->then(function (string $payload) use ($data, $hr) {
                try {
                    if (count(self::_split($data->message->content)) > 2) {
                        $hr->notes = self::arg_substr($data->message->content, 2);
                    }

                    $hr->buyer = $data->message->member->id;
                    $hr->siteData = json_decode($payload);
                    $hr->fillItems();

                    if ($hr->insert()) {
                        $embed = self::getEmbed($hr, $data, true);
                        $embed->setTitle("Request posted");
                        $embed->setDescription(sprintf("Haulers: Claim this request by running `!claim %s`",Snowflake::format($hr->id)));

                        self::pinUpdate($data->huntress);
                        return $data->message->channel->send("<@&723984678117441646>, a member has posted a haul request.",
                            ['embed' => $embed]);
                    } else {
                        throw new \Exception("unable to insert database row. :(");
                    }

                } catch (Throwable $e) {
                    return self::exceptionHandler($data->message, $e, false);
                }

            }, function (Throwable $e) use ($data) {
                return self::exceptionHandler($data->message, $e, false);
            });

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    public static function listHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (self::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            $embed = self::getListEmbed($data->guild);

            return $data->message->channel->send("", ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    public static function claimHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (self::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            if (count(self::_split($data->message->content)) != 2) {
                return self::error($data->message, "Unable to parse",
                    "Usage: `!claim ID` - run `!requests` to see open requests.");
            }
            $id = self::arg_substr($data->message->content, 1, 1);

            $res = $data->huntress->db->executeQuery("select idTrade, site from evetrader where idTrade = ?",
                [Snowflake::parse($id)],
                ['integer']);

            if ($row = $res->fetchAssociative()) {
                if (!(new ReflectionClass($row['site']))->isSubclassOf("Iteronbot\HaulRequest")) {
                    throw new \Exception(sprintf("non-HaulRequest class in database! Tell sauce bosses to look into `%s`",
                        json_encode($row)));
                }
                /** @var HaulRequest $req */
                $req = new $row['site']($row['idTrade']);
            } else {
                return self::error($data->message, "Unknown request ID",
                    "I don't have that ID in my database. If you're certain it's typed correctly, ask a sauce boss for help.");
            }

            if (!is_null($req->seller)) {
                return self::error($data->message, "Already claimed",
                    "This request has already been claimed. Please ask them to make another.");
            }

            $req->claim($data->message->member->id);
            $embed = self::getEmbed($req, $data, true);
            $embed->setTitle("Request claimed");
            $embed->setDescription(sprintf("Hauler: Once you've contracted this request, run `!complete %s`",Snowflake::format($req->id)));

            self::pinUpdate($data->huntress);
            return $data->message->channel->send(sprintf("<@%s> claimed <@%s>'s request",
                $req->seller, $req->buyer), ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    public static function unclaimHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (self::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            if (count(self::_split($data->message->content)) != 2) {
                return self::error($data->message, "Unable to parse",
                    "Usage: `!unclaim ID`");
            }
            $id = self::arg_substr($data->message->content, 1, 1);

            $res = $data->huntress->db->executeQuery("select idTrade, site from evetrader where idTrade = ?",
                [Snowflake::parse($id)],
                ['integer']);

            if ($row = $res->fetchAssociative()) {
                if (!(new ReflectionClass($row['site']))->isSubclassOf("Iteronbot\HaulRequest")) {
                    throw new \Exception(sprintf("non-HaulRequest class in database! Tell sauce bosses to look into `%s`",
                        json_encode($row)));
                }
                /** @var HaulRequest $req */
                $req = new $row['site']($row['idTrade']);
            } else {
                return self::error($data->message, "Unknown request ID",
                    "I don't have that ID in my database. If you're certain it's typed correctly, ask a sauce boss for help.");
            }

            if ($req->buyer != $data->message->member->id && $req->seller != $data->message->member->id && !$data->message->member->roles->has(self::MODROLE)) {
                return self::error($data->message, "This isn't yours!",
                    "Requests can only be unclaimed by the original poster, the person who claimed it, or a Director.");
            }

            if (is_null($req->seller)) {
                return self::error($data->message, "Not claimed!",
                    "Nobody has claimed this request, so it cannot be unclaimed. To remove a request, use `!complete`.");

            }

            $req->unclaim();
            $embed = self::getEmbed($req, $data, true);
            $embed->setTitle("Request released");
            $embed->setDescription(sprintf("To claim this request, run `!claim %s`",Snowflake::format($req->id)));

            self::pinUpdate($data->huntress);
            return $data->message->channel->send(sprintf("<@%s> released <@%s>'s request",
                $data->message->member->id, $req->buyer), ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    public static function completeHandler(EventData $data): ?PromiseInterface
    {
        try {
            $can = false;
            foreach (self::ROLES as $r) {
                $can = $can || $data->message->member->roles->has($r);
            }
            if (!$can) {
                return $data->message->react("😔");
            }

            if (count(self::_split($data->message->content)) != 2) {
                return self::error($data->message, "Unable to parse",
                    "Usage: `!complete ID` - run `!requests` to see open requests.");
            }
            $id = self::arg_substr($data->message->content, 1, 1);

            $res = $data->huntress->db->executeQuery("select idTrade, site from evetrader where idTrade = ?",
                [Snowflake::parse($id)],
                ['integer']);

            if ($row = $res->fetchAssociative()) {
                if (!(new ReflectionClass($row['site']))->isSubclassOf("Iteronbot\HaulRequest")) {
                    throw new \Exception(sprintf("non-HaulRequest class in database! Tell sauce bosses to look into `%s`",
                        json_encode($row)));
                }
                /** @var HaulRequest $req */
                $req = new $row['site']($row['idTrade']);
            } else {
                return self::error($data->message, "Unknown request ID",
                    "I don't have that ID in my database. If you're certain it's typed correctly, ask a sauce boss for help.");
            }

            if ($req->buyer != $data->message->member->id && $req->seller != $data->message->member->id && !$data->message->member->roles->has(self::MODROLE)) {
                return self::error($data->message, "This isn't yours!",
                    "Requests can only be completed by the original poster, the person who claimed it, or a Director.");
            }

            $req->complete();

            $embed = self::getEmbed($req, $data, false);
            $embed->setTitle("Request completed");

            self::pinUpdate($data->huntress);
            return $data->message->channel->send(sprintf("<@%s>'s request has been completed.", $req->buyer), ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function getUnfulfilledTrades(Guild $guild): Collection {
        $res = $guild->client->db->executeQuery("select idTrade, site from evetrader where completeTime is null");

        $orders = new Collection();
        foreach ($res->fetchAllAssociative() as $row) {
            if (!(new ReflectionClass($row['site']))->isSubclassOf("Iteronbot\HaulRequest")) {
                throw new \Exception(sprintf("non-HaulRequest class in database! Tell sauce bosses to look into `%s`",
                    json_encode($row)));
            }
            $orders->set($row['idTrade'], new $row['site']($row['idTrade']));
        }
        return $orders;
    }

    private static function getAllTrades(Guild $guild): Collection {
        $res = $guild->client->db->executeQuery("select idTrade, site from evetrader");

        $orders = new Collection();
        foreach ($res->fetchAllAssociative() as $row) {
            if (!(new ReflectionClass($row['site']))->isSubclassOf("Iteronbot\HaulRequest")) {
                throw new \Exception(sprintf("non-HaulRequest class in database! Tell sauce bosses to look into `%s`",
                    json_encode($row)));
            }
            $orders->set($row['idTrade'], new $row['site']($row['idTrade']));
        }
        return $orders;
    }

    private static function getListEmbed(Guild $guild): MessageEmbed {
        $orders = self::getUnfulfilledTrades($guild);

        // sort orders pls
        $orders = $orders->sortCustom(function (HaulRequest $a, HaulRequest $b) {
            $x = !is_null($a->seller) <=> !is_null($b->seller);
            $y = $a->createTime <=> $b->createTime;
            if ($x == 0) {
                return $y;
            }
            return $x;
        });


        $embed = new MessageEmbed();
        $embed->setAuthor($guild->name,
            $guild->getIconURL(64) ?? null);
        $embed->setColor($guild->id % 0xFFFFFF);
        $embed->setTimestamp(time());
        $embed->setFooter("Click the 🔗 for request details.");

        $embed->setTitle(number_format($orders->count()) . " Open Requests");
        $embed->setDescription("Create a request by running `!request`\n" .
            "Legend:\n" .
            "> 🔗 Appraisal Link *(I support Evepraisal or Janice)*\n" .
            "> 💸 Price (ISK) *(Sell minimum at the indicated market. Actual price may vary)*\n" .
            "> 📦 Volume (m³)\n" .
            "> 🧾 Claimaint *(Claim a request by running `!claim ID`)*\n" .
            "> ⏱️ Time since creation/claim\n");

        $lines = $orders->map(function (HaulRequest $v) use ($guild) {

            $str = sprintf("[🔗](%s) `%s` from <@%s>, 💸 %s, 📦 %s, 🧾 %s, ⏱️ %s",
                $v->getLink(), Snowflake::format($v->id), $v->buyer,
                number_format($v->getTotalPrice()),
                number_format($v->getTotalVolume()),
                (!is_null($v->seller) && $guild->members->has($v->seller)) ? "<@{$v->seller}>" : "Unclaimed",
                max($v->createTime, $v->claimTime)->diffForHumans()
            );
            if (mb_strlen($v->notes) > 0) {
                $str .= PHP_EOL . "> " . $v->notes;
            }
            return $str;
        })->implode(null, PHP_EOL);

        if ($orders->count() == 0) {
            $lines = "There are no requests right now. 😔";
        }

        $roles = MessageHelpers::splitMessage($lines, ['maxLength' => 1024]);
        $firstField = true;
        foreach ($roles as $role) {
            $embed->addField($firstField ? "Requests" : "Requests (cont.)", $role);
            $firstField = false;
        }

        return $embed;
    }

    private static function getEmbed(HaulRequest $hr, EventData $data, bool $includeItems = true): MessageEmbed {
        $price = $hr->getTotalPrice();
        $fee = $price * 0.05;
        $priceMsg = sprintf("%s ISK\n%s ISK fee\n%s ISK total",
            number_format($price),
            number_format($fee),
            number_format($price + $fee),
        );

        $embed = new MessageEmbed();
        $embed->setAuthor($data->message->member->displayName,
            $data->message->member->user->getAvatarURL(64) ?? null);
        $embed->setColor($data->message->member->id % 0xFFFFFF);
        $embed->setTimestamp(time());
        $embed->setURL($hr->getLink());

        $embed->addField("Items", $hr->getLink());
        $embed->addField("💸 Value ({$hr->getMarket()} Sell)", $priceMsg, true);
        $embed->addField("📦 Payload", number_format($hr->getTotalVolume()) . " m³", true);
        if (mb_strlen($hr->notes) > 0) {
            $embed->addField("User notes", $hr->notes);
        }

        $embed->setFooter(sprintf("Request ID %s", Snowflake::format($hr->id)));

        if ($includeItems) {
            $itemStr = $hr->items->map(function (Item $v) {
                return sprintf("%s x%s (💸 %s ISK, 📦 %s m³)",
                    $v->name, number_format($v->amount),
                    number_format($v->price * $v->amount),
                    number_format($v->volume * $v->amount)
                );
            })->implode(null, PHP_EOL);

            $roles = MessageHelpers::splitMessage($itemStr, ['maxLength' => 1024]);
            $firstField = true;
            foreach ($roles as $role) {
                $embed->addField($firstField ? "Items" : "Items (cont.)", $role);
                $firstField = false;
            }
        }

        return $embed;
    }
}
