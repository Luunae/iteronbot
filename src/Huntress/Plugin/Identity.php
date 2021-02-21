<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;

/**
 * Just some fun Huntress stuff.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Identity implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->setCallback([self::class, "changeStatus"])
            ->setPeriodic(15)
        );
    }

    public static function changeStatus(Huntress $bot): ?PromiseInterface
    {
        $opts = [
            'EVE: Online',
            'Desert Bus (but in space)',
            'in the corpse display case',
            'camping a wormhole',
            'solo mining in a Praxis',
            'in Wormyhome',
            'The Internationale',
            'with the server',
            'with a ball of yarn',
            'with Rythm',
        ];
        return $bot->user->setPresence([
            'status' => 'online',
            'game' => ['name' => $opts[array_rand($opts)], 'type' => 0],
        ]);
    }
}
