<?php defined('BASE_PATH') or exit('No direct script access allowed');

$config = [];
$config['enable'] = true;
$config['minimal_system_version'] = '0.3';
$config['tlg_bot']['token'] = 'paste telegram bot token here';
$config['tlg_bot']['allowed_chats'] = [
    '-1001300415821', // пожилая конфа
    '-1001620210287', // андрей викторович
    '427384175',      // лс бота с анчоусом
    '479117556',      // лс Юли с ботом
];
$config['tlg_bot']['allowed_users'] = [
    'nik' => [ 'id' => '427384175' ],
    'ser' => [ 'id' => '895379852' ],
    'julia' => [ 'id' => '479117556', 'group' => 'julia']
];

$config['tlg_bot']['pairs_time'] = [
    ["8:00", "9:30"],
    ["9:40", "11:10"],
    ["11:20", "12:50"],
    ["13:15", "14:45"],
    ["15:00", "16:30"],
    ["16:40", "18:10"],
    ["18:20", "19:50"],
    ["19:55", "21:25"]
];