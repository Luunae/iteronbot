<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Iteronbot;


class Item
{
    public float $price;
    public float $volume;

    public string $name;

    public int $amount;

    function __construct(array $x)
    {
        foreach ($x as $k => $v) {
            $this->$k = $v;
        }
    }
}
