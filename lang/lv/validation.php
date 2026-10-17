<?php

declare(strict_types=1);

/*
 * Validation messages (LV). Only the rules used by the app and Filament forms; calm, explain-the-fix wording.
 */
return [
    'accepted' => 'Lūdzu, apstipriniet laukā “:attribute”.',
    'array' => 'Laukam “:attribute” jābūt sarakstam.',
    'boolean' => 'Laukam “:attribute” jābūt jā vai nē.',
    'date' => 'Laukā “:attribute” jābūt derīgam datumam.',
    'email' => 'Pārbaudiet e-pasta adresi laukā “:attribute”.',
    'in' => 'Izvēlieties derīgu vērtību laukā “:attribute”.',
    'integer' => 'Laukā “:attribute” jābūt veselam skaitlim.',
    'max' => [
        'numeric' => 'Laukā “:attribute” jābūt ne vairāk par :max.',
        'string' => 'Laukā “:attribute” var būt ne vairāk kā :max rakstzīmes.',
        'array' => 'Laukā “:attribute” var būt ne vairāk kā :max vienumi.',
        'file' => 'Fails “:attribute” nedrīkst pārsniegt :max KB.',
    ],
    'min' => [
        'numeric' => 'Laukā “:attribute” jābūt vismaz :min.',
        'string' => 'Laukā “:attribute” jābūt vismaz :min rakstzīmēm.',
        'array' => 'Laukā “:attribute” jābūt vismaz :min vienumiem.',
        'file' => 'Failam “:attribute” jābūt vismaz :min KB.',
    ],
    'numeric' => 'Laukā “:attribute” jābūt skaitlim.',
    'regex' => 'Lauka “:attribute” formāts nav derīgs.',
    'required' => 'Lūdzu, aizpildiet lauku “:attribute”.',
    'same' => 'Laukiem “:attribute” un “:other” jāsakrīt.',
    'string' => 'Laukā “:attribute” jābūt tekstam.',
    'unique' => 'Šāda vērtība laukā “:attribute” jau ir izmantota.',
    'url' => 'Laukā “:attribute” jābūt derīgai saitei.',
    'attributes' => [
        'email' => 'e-pasts',
        'code' => 'kods',
        'pin' => 'PIN',
        'pinConfirm' => 'PIN atkārtojums',
        'role' => 'loma',
    ],
];
