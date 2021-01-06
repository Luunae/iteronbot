<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Iteronbot;


class JaniceRequest extends HaulRequest
{

    public function __construct(?int $id = null)
    {
        parent::__construct($id);
    }

    public function getLink(): string
    {
        return sprintf("https://janice.e-351.com/a/%s", $this->siteData->code);
    }

    public function fillItems(): HaulRequest
    {
        foreach ($this->siteData->items as $item) {
            $x = new Item([
                'price' => $item->sellPriceMin,
                'name' => $item->itemType->name,
                'volume' => $item->itemType->packagedVolume,
                'amount' => $item->amount,
            ]);
            $this->items->set($item->itemType->eid, $x);
        }
        return $this;
    }

    public function getMarket(): string
    {
        return mb_convert_case($this->siteData->market->name, MB_CASE_TITLE);
    }

    public function getAPI(string $apiID): string
    {
        global $config; // i know

        return sprintf("https://janice.e-351.com/api/rest/v1/appraisal/%s?key=%s", $apiID, $config['janice']);
    }
}
