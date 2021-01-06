<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

$config = [];

// this just gets shipped off to Doctrine/DBAL, so it can be any DB supported by them :)
$config['database'] = "mysql://huntress:PASSWORD@localhost/huntress";

// Get this from discord
$config['botToken'] = "xxx";

// how much logging you want on the console.
$config['logLevel'] = \Monolog\Logger::INFO;

// Discord IDs of users who you are comfortable being able to execute arbitary code on your machine.
$config['evalUsers'] = [
//    '297969955356540929', // keira
];

// janice api key
$config['janice'] = "xxx";
