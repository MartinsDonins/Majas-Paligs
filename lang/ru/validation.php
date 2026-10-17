<?php

declare(strict_types=1);

/*
 * Validation messages (RU). Only the rules used by the app and Filament forms.
 */
return [
    'accepted' => 'Пожалуйста, подтвердите поле «:attribute».',
    'array' => 'Поле «:attribute» должно быть списком.',
    'boolean' => 'Поле «:attribute» должно быть «да» или «нет».',
    'date' => 'В поле «:attribute» должна быть правильная дата.',
    'email' => 'Проверьте адрес e-mail в поле «:attribute».',
    'in' => 'Выберите правильное значение в поле «:attribute».',
    'integer' => 'В поле «:attribute» должно быть целое число.',
    'max' => [
        'numeric' => 'Значение поля «:attribute» должно быть не больше :max.',
        'string' => 'В поле «:attribute» может быть не больше :max символов.',
        'array' => 'В поле «:attribute» может быть не больше :max элементов.',
        'file' => 'Файл «:attribute» не должен превышать :max КБ.',
    ],
    'min' => [
        'numeric' => 'Значение поля «:attribute» должно быть не меньше :min.',
        'string' => 'В поле «:attribute» должно быть не меньше :min символов.',
        'array' => 'В поле «:attribute» должно быть не меньше :min элементов.',
        'file' => 'Файл «:attribute» должен быть не меньше :min КБ.',
    ],
    'numeric' => 'В поле «:attribute» должно быть число.',
    'regex' => 'Неправильный формат поля «:attribute».',
    'required' => 'Пожалуйста, заполните поле «:attribute».',
    'same' => 'Поля «:attribute» и «:other» должны совпадать.',
    'string' => 'В поле «:attribute» должен быть текст.',
    'unique' => 'Такое значение поля «:attribute» уже используется.',
    'url' => 'В поле «:attribute» должна быть правильная ссылка.',
    'attributes' => [
        'email' => 'e-mail',
        'code' => 'код',
        'pin' => 'PIN',
        'pinConfirm' => 'повтор PIN',
        'role' => 'роль',
    ],
];
