<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PrivacyNoticeVersion;
use Illuminate\Database\Seeder;

/**
 * Draft privacy notice texts (GOV-005). Seeded UNPUBLISHED: a platform admin publishes them in the platform
 * panel after legal + native-RU review. Building templates carry {controller.name} / {controller.contact}.
 */
final class PrivacyNoticeSeeder extends Seeder
{
    public function run(): void
    {
        PrivacyNoticeVersion::query()->firstOrCreate(['kind' => 'platform_account', 'version' => '2026-10-draft-1'], [
            'covers_modules' => ['account'],
            'body' => [
                'lv' => implode("\n\n", [
                    'Konta privātuma paziņojums (MELNRAKSTS — jāpārbauda juristam).',
                    'Pārzinis: CoreDigify. Mēs apstrādājam tikai to, kas vajadzīgs ieejai un drošībai: e-pastu vai ieejas kodu, PIN jaucējvērtību, ierīču atcerēšanās žetonus un drošības žurnālus (IP adreses glabājam saīsinātas un pēc 90 dienām pseidonimizētas).',
                    'Tiesiskais pamats: pakalpojuma noteikumu izpilde (VDAR 6. panta 1. punkta b) apakšpunkts) un drošība (f) apakšpunkts). Infrastruktūras žurnāli tiek glabāti 14 dienas, kļūdu notikumi 30 dienas, ieejas žetoni 1 dienu.',
                    'Jums ir tiesības piekļūt saviem datiem, tos lejupielādēt un dzēst sadaļā “Privātums”, kā arī iesniegt sūdzību Datu valsts inspekcijā.',
                ]),
                'ru' => implode("\n\n", [
                    'Уведомление о конфиденциальности учётной записи (ЧЕРНОВИК — требует проверки юристом).',
                    'Контролёр: CoreDigify. Мы обрабатываем только то, что нужно для входа и безопасности: e-mail или код входа, хеш PIN, токены запоминания устройства и журналы безопасности (IP-адреса храним сокращёнными и через 90 дней псевдонимизируем).',
                    'Правовое основание: исполнение условий сервиса (ст. 6(1)(b) GDPR) и безопасность (ст. 6(1)(f)). Журналы инфраструктуры хранятся 14 дней, события ошибок 30 дней, токены входа 1 день.',
                    'Вы можете получить, скачать и удалить свои данные в разделе «Конфиденциальность», а также подать жалобу в Государственную инспекцию данных (DVI).',
                ]),
            ],
        ]);

        PrivacyNoticeVersion::query()->firstOrCreate(['kind' => 'building_template', 'version' => '2026-10-draft-1'], [
            'covers_modules' => ['core', 'heating', 'outages'],
            'body' => [
                'lv' => implode("\n\n", [
                    'Mājas privātuma paziņojums (MELNRAKSTS — jāpārbauda juristam).',
                    'Pārzinis: {controller.name} ({controller.contact}). Apstrādātājs: CoreDigify saskaņā ar datu apstrādes līgumu.',
                    'Mērķis: dzīvokļu piederības pārbaude, kāpņu telpu vecāko darbs, ziņojumi par apkuri un atslēgumiem. Iedzīvotāji redz tikai stāvadu kopsavilkumus, nevis citu dzīvokļu ziņojumus.',
                    'Mēs nevācam vārdus un tālruņa numurus. Ziņojumi par apkuri tiek glabāti līdz apkures sezonas beigām + 1 mēnesis, audita žurnāls 24 mēnešus.',
                    'Jums ir tiesības piekļūt saviem datiem, tos lejupielādēt un dzēst, kā arī iesniegt sūdzību Datu valsts inspekcijā.',
                ]),
                'ru' => implode("\n\n", [
                    'Уведомление о конфиденциальности дома (ЧЕРНОВИК — требует проверки юристом).',
                    'Контролёр: {controller.name} ({controller.contact}). Обработчик: CoreDigify по договору об обработке данных.',
                    'Цель: проверка принадлежности к квартире, работа старших по подъезду, сообщения об отоплении и отключениях. Жители видят только сводки по стоякам, а не сообщения других квартир.',
                    'Мы не собираем имена и номера телефонов. Сообщения об отоплении хранятся до конца отопительного сезона + 1 месяц, журнал аудита 24 месяца.',
                    'Вы можете получить, скачать и удалить свои данные, а также подать жалобу в Государственную инспекцию данных (DVI).',
                ]),
            ],
        ]);
    }
}
