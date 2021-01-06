<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Collect\Collection;
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

    const GUILD = 349058708304822273; // masturbatorium - test
    const ROLES = [
        365084727750688768, // cum elemental
        606468384472825884, // interns
    ];
    const MODROLE = 365084727750688768;

    /*
    const GUILD = 616779348250329128; // be nice - prod
    const ROLES = [
        616781834939793428, // director
        616781942167175198, // comrade
    ];
    const MODROLE = 616781834939793428;
    */

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
            ->addCommand("complete")
            ->addGuild(self::GUILD)
            ->setCallback([self::class, "completeHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );

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
                        $embed = new MessageEmbed();
                        $embed->setAuthor($data->message->member->displayName,
                            $data->message->member->user->getAvatarURL(64) ?? null);
                        $embed->setColor($data->message->member->id % 0xFFFFFF);
                        $embed->setTimestamp(time());

                        $embed->setTitle("Request posted");
                        $embed->setDescription(sprintf("Sellers: Claim this request by running `!claim %s`",
                            Snowflake::format($hr->id)));
                        $embed->setURL($hr->getLink());

                        $embed->addField("Items", $hr->getLink());
                        $embed->addField("💸 Value ({$hr->getMarket()} Sell)",
                            number_format($hr->getTotalPrice()) . " ISK", true);
                        $embed->addField("📦 Payload", number_format($hr->getTotalVolume()) . " m³", true);
                        if (mb_strlen($hr->notes) > 0) {
                            $embed->addField("User notes", $hr->notes);
                        }

                        $embed->setFooter(sprintf("Request ID %s", Snowflake::format($hr->id)));

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

            $res = $data->huntress->db->executeQuery("select idTrade, site from evetrader where completeTime is null");

            $orders = new Collection();
            foreach ($res->fetchAllAssociative() as $row) {
                if (!(new ReflectionClass($row['site']))->isSubclassOf("Iteronbot\HaulRequest")) {
                    throw new \Exception(sprintf("non-HaulRequest class in database! Tell sauce bosses to look into `%s`",
                        json_encode($row)));
                }
                $orders->set($row['idTrade'], new $row['site']($row['idTrade']));
            }

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
            $embed->setAuthor($data->message->guild->name,
                $data->message->guild->getIconURL(64) ?? null);
            $embed->setColor($data->message->member->id % 0xFFFFFF);
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

            $lines = $orders->map(function (HaulRequest $v) use ($data) {

                $str = sprintf("[🔗](%s) `%s` from <@%s>, 💸 %s, 📦 %s, 🧾 %s, ⏱️ %s",
                    $v->getLink(), Snowflake::format($v->id), $v->buyer,
                    number_format($v->getTotalPrice()),
                    number_format($v->getTotalVolume()),
                    (!is_null($v->seller) && $data->guild->members->has($v->seller)) ? "<@{$v->seller}>" : "Unclaimed",
                    max($v->createTime, $v->claimTime)->diffForHumans()
                );
                if (mb_strlen($v->notes) > 0) {
                    $str .= PHP_EOL . "> " . $v->notes;
                }
                return $str;
            })->implode(null, PHP_EOL);

            $roles = MessageHelpers::splitMessage($lines, ['maxLength' => 1024]);
            $firstField = true;
            foreach ($roles as $role) {
                $embed->addField($firstField ? "Requests" : "Requests (cont.)", $role);
                $firstField = false;
            }

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

            $embed = new MessageEmbed();
            $embed->setAuthor($data->message->member->displayName,
                $data->message->member->user->getAvatarURL(64) ?? null);
            $embed->setColor($data->message->member->id % 0xFFFFFF);
            $embed->setTimestamp(time());

            $embed->setTitle("Request claimed");
            $embed->setDescription(sprintf("Once you are done, the poster should run `!complete %s`",
                Snowflake::format($req->id)));
            $embed->setURL($req->getLink());

            $embed->addField("Items", $req->getLink());
            $embed->addField("💸 Value ({$req->getMarket()} Sell)",
                number_format($req->getTotalPrice()) . " ISK", true);
            $embed->addField("📦 Payload", number_format($req->getTotalVolume()) . " m³", true);
            if (mb_strlen($req->notes) > 0) {
                $embed->addField("User notes", $req->notes);
            }

            $embed->setFooter(sprintf("Request ID %s", Snowflake::format($req->id)));

            $itemStr = $req->items->map(function (Item $v) {
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


            return $data->message->channel->send(sprintf("<@%s> claimed <@%s>'s request",
                $req->seller, $req->buyer), ['embed' => $embed]);
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

            $embed = new MessageEmbed();
            $embed->setAuthor($data->message->member->displayName,
                $data->message->member->user->getAvatarURL(64) ?? null);
            $embed->setColor($data->message->member->id % 0xFFFFFF);
            $embed->setTimestamp(time());

            $embed->setTitle("Request completed!");
            $embed->setURL($req->getLink());

            $embed->addField("Items", $req->getLink());
            $embed->addField("💸 Value ({$req->getMarket()} Sell)",
                number_format($req->getTotalPrice()) . " ISK", true);
            $embed->addField("📦 Payload", number_format($req->getTotalVolume()) . " m³", true);
            if (mb_strlen($req->notes) > 0) {
                $embed->addField("User notes", $req->notes);
            }

            $embed->setFooter(sprintf("Request ID %s", Snowflake::format($req->id)));

            return $data->message->channel->send(sprintf("%s has marked a request completed by <@%s>",
                $data->message->member, $req->seller), ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
