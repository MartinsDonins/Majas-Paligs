<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LegalNotice;
use Illuminate\Database\Seeder;

/**
 * Default "current Riga parking rule" banner (PARK-007, PLANNING §2.1) — platform data, editable in the platform
 * panel (LegalNoticeResource). Wording kept general on purpose: verify the in-force date of Ceļu satiksmes likums
 * Art. 19(6) on likumi.lv (MANUAL-TODO) before relying on it.
 */
final class LegalNoticeSeeder extends Seeder
{
    public function run(): void
    {
        LegalNotice::query()->firstOrCreate(['key' => 'parking.riga.current_rule', 'valid_from' => '2026-06-02 00:00:00'], [
            'jurisdiction' => 'LV-RIX',
            'source_url' => 'https://likumi.lv',
            'body' => [
                'lv' => 'Mājas stāvvietā pie vējstikla jābūt skaidri redzamai caurlaidei (pamata vai viesu). Pašvaldības policija pārbauda pēc iedzīvotāja izsaukuma.',
                'ru' => 'На парковке дома пропуск (основной или гостевой) должен быть хорошо виден за лобовым стеклом. Муниципальная полиция проверяет по вызову жителя.',
            ],
        ]);
    }
}
