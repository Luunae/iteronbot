<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Iteronbot;


class EvepraisalRequest extends HaulRequest
{

    public function __construct(?int $id = null)
    {
        parent::__construct($id);
    }

    public function getLink(): string
    {
        return sprintf("https://evepraisal.com/a/%s", $this->siteData->id);
    }

    public function fillItems(): HaulRequest
    {
        foreach ($this->siteData->items as $item) {
            $x = new Item([
                'price' => $item->prices->sell->min,
                'name' => $item->name,
                'volume' => $item->typeVolume,
                'amount' => $item->quantity,
            ]);
            $this->items->set($item->typeID, $x);
        }
        return $this;
    }

    public function getMarket(): string
    {
        return mb_convert_case($this->siteData->market_name, MB_CASE_TITLE);
    }

    public function getAPI(string $apiID): string
    {
        return sprintf("https://evepraisal.com/a/%s.json", $apiID);
    }
}
