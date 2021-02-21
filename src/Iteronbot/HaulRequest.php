<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Iteronbot;


use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use Huntress\DatabaseFactory;
use Huntress\Snowflake;
use stdClass;

abstract class HaulRequest
{
    public int $id;

    public Collection $items;

    public Carbon $createTime;

    /**
     * @var Carbon
     */
    public $claimTime;
    /**
     * @var Carbon
     */
    public $completeTime;

    public int $buyer;

    /**
     * why are typed properties such a pain in php
     * @var int
     */
    public $seller;

    /**
     * @var string
     */
    public $notes;

    public object $siteData;

    public function __construct(?int $id = null)
    {
        if (is_int($id)) {
            $res = DatabaseFactory::get()->executeQuery("select * from evetrader where idTrade = ?", [$id],
                ['integer']);
            if ($row = $res->fetchAssociative()) {
                $this->id = $row['idTrade'];
                $this->buyer = $row['idBuyer'];
                $this->createTime = new Carbon($row['createTime']);

                if (!is_null($row['idSeller'])) {
                    $this->seller = $row['idSeller'];
                }
                if (!is_null($row['claimTime'])) {
                    $this->claimTime = new Carbon($row['claimTime']);
                }
                if (!is_null($row['completeTime'])) {
                    $this->completeTime = new Carbon($row['completeTime']);
                }

                if (!is_null($row['siteData'])) {
                    $this->siteData = json_decode($row['siteData']);
                }

                if (!is_null($row['notes'])) {
                    $this->notes = $row['notes'];
                }

                $this->items = (new Collection(json_decode($row['items'], true)))
                    ->map(fn($v) => new Item($v));
            } else {
                throw new \Exception("unknown request ID");
            }
        } else {
            $this->id = Snowflake::generate();
            $this->items = new Collection();
            $this->createTime = Carbon::now();
            $this->siteData = new stdClass();
        }
    }

    public abstract function getAPI(string $apiID): string;

    public function getTotalPrice(): float
    {
        return $this->items->reduce(fn($total = 0, Item $v = null) => $total + ($v->price * $v->amount));
    }

    public function getTotalVolume(): float
    {
        return $this->items->reduce(fn($total = 0, Item $v = null) => $total + ($v->volume * $v->amount));
    }

    public abstract function getLink(): string;

    public abstract function getMarket(): string;

    public abstract function fillItems(): self;

    public function insert(): bool
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->insert('evetrader')
            ->values([
                'idTrade' => '?',
                'idBuyer' => '?',
                'createTime' => '?',
                'items' => '?',
                'notes' => '?',
                'siteData' => '?',
                'site' => '?',
            ])
            ->setParameter(0, $this->id, "integer")
            ->setParameter(1, $this->buyer, "integer")
            ->setParameter(2, Carbon::now(), "datetime")
            ->setParameter(3, json_encode($this->items->all()), "string")
            ->setParameter(4, $this->notes, "string")
            ->setParameter(5, json_encode($this->siteData), "string")
            ->setParameter(6, get_class($this), "string");

        return $qb->execute();
    }

    public function claim(int $seller): self
    {
        $now = Carbon::now();
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->update('evetrader')
            ->set("idSeller", "?")
            ->set("claimTime", "?")
            ->where('idTrade = ?')
            ->setParameter(0, $seller, "integer")
            ->setParameter(1, $now, "datetime")
            ->setParameter(2, $this->id, "integer");

        if ($qb->execute()) {
            $this->seller = $seller;
            $this->claimTime = $now;
            return $this;
        } else {
            throw new \Exception("Failed to update database?");
        }
    }

    public function unclaim(): self
    {
        $now = Carbon::now();
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->update('evetrader')
            ->set("idSeller", "?")
            ->set("claimTime", "?")
            ->where('idTrade = ?')
            ->setParameter(0, null, "integer")
            ->setParameter(1, null, "datetime")
            ->setParameter(2, $this->id, "integer");

        if ($qb->execute()) {
            $this->seller = null;
            $this->claimTime = null;
            return $this;
        } else {
            throw new \Exception("Failed to update database?");
        }
    }

    public function complete(): self
    {
        $now = Carbon::now();
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->update('evetrader')
            ->set("completeTime", "?")
            ->where('idTrade = ?')
            ->setParameter(0, $now, "datetime")
            ->setParameter(1, $this->id, "integer");

        if ($qb->execute()) {
            $this->completeTime = $now;
            return $this;
        } else {
            throw new \Exception("Failed to update database?");
        }
    }


}
