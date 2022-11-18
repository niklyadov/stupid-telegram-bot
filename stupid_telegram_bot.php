<?php defined('BASE_PATH') OR exit('No direct script access allowed');

/*
    * Stupid telegram bot (v.3.3.0) ± 14.02.2022
    * copy., 2021, @Niklyadov
*/

class module_stupid_telegram_bot extends CsModule
{
    private TelegramBot\Api\Client $_tlgBotApi;

    private $numbers_smiles = [
        1 => '1️⃣',
        2 => '2️⃣',
        3 => '3️⃣',
        4 => '4️⃣',
        5 => '5️⃣',
        6 => '6️⃣',
        7 => '7️⃣',
        8 => '8️⃣',
        9 => '9️⃣'
    ];

    private $days_of_week = [
        "пн",
        "вт",
        "ср",
        "чт",
        "пт",
        "сб",
    ];

    private $commands = [];
    private $cachedStorage = null;
    private $userGroup = null;

    public function OnLoad()
    {
        $this->commands = [
            'start' => [
                'allowed_users' => null,
                'log_usage_in_allowed_chats' => false,
                'log_usage_in_unallowed_chats' => true,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    return "";
                }
            ],
            'week' => [
                'allowed_users' => null,
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    $monday_of_this_week_time = date("d.m", strtotime('monday this week'));
                    $sunday_of_this_week_time = date("d.m", strtotime('sunday this week'));
        
                    return "Щас вроде {$this->getCurrentWeekText()} неделя (с {$monday_of_this_week_time} по {$sunday_of_this_week_time}))";
                }
            ],
            'weekreverse' => [
                'allowed_users' => ['nik', 'cer', 'julia'],
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    $this->weekReverse();
                    return "✅ ok, week has been reversed ({$this->getCurrentWeekText()})";
                }
            ],
            'newrasp' => [
                'allowed_users' => ['nik', 'cer', 'julia'],
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    $reply = $message->getReplyToMessage();
                    if(empty($reply)) return "Please, in reply!";

                    $document = $reply->getDocument();
                    $checkReplyDocument = $this->checkReplyDocument($document, "image/png");

                    if(!empty($checkReplyDocument)) {
                        return $checkReplyDocument;
                    }

                    # Send "Typing..."
                    $bot->sendChatAction(
                        $message->getChat()->getId(),
                        'typing'
                    );

                    $prefix = isset($this->userGroup) ? $this->userGroup : '';
                    $file = $this->directory . $prefix . 'rasp.png';

                    $token = $this->config['tlg_bot']['token'];
                    $filePath = $bot->getFile($document->getFileId())->getFilePath();
                    file_put_contents($file, fopen("https://api.telegram.org/file/bot$token/$filePath", 'r'));

                    return "Ok. Rasp updated!";
                }
            ],
            'newplan' => [
                'allowed_users' => ['nik', 'julia'],
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    $reply = $message->getReplyToMessage();
                    if(empty($reply)) return "Please, in reply!";

                    $document = $reply->getDocument();
                    $checkReplyDocument = $this->checkReplyDocument($document, "application/json");
        
                    if(!empty($checkReplyDocument)) {
                        return $checkReplyDocument;
                    }

                    # Send "Typing..."
                    $bot->sendChatAction(
                        $message->getChat()->getId(),
                        'typing'
                    );
        
                    $filePath = $bot->getFile($document->getFileId())->getFilePath();
                    $token = $this->config['tlg_bot']['token'];
                    if ($stream = fopen("https://api.telegram.org/file/bot$token/$filePath", 'r')) {
                        $plan = json_decode(stream_get_contents($stream), true);
                        fclose($stream);

                        if ($this->storageUpdateData($plan['subjects'], 'subjects', true)) {
                            return "Ok. New plan has been set";
                        }
                    }
        
                    return "Cannot upload new plan.";
                }
            ],
            'time' => [
                'allowed_users' => null,
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    return $this->timeText();
                }
            ],
            'rasp' => [
                'allowed_users' => null,
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    $prefix = isset($this->userGroup) ? $this->userGroup : '';
                    $file = $this->directory . $prefix . 'rasp.png';
                    $document = new CURLFile($file);
                    return ['type'=> 'message', 'value' => $bot->sendDocument($chatId, $document)];
                }
            ],
            'plan' => [
                'allowed_users' => null,
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    return $this->planText();
                }
            ],
            'plan2' => [
                'allowed_users' => null,
                'log_usage_in_allowed_chats' => true,
                'log_usage_in_unallowed_chats' => false,
                'clean_message' => true,
                'function_call' => function ($bot, $chatId, $message) {
                    return $this->planText(true);
                }
            ]
        ];

        $this->cubsystem->getRouter()
            ->all("/api/stupid_telegram_bot", [$this, 'CommandHandler']);
    }

    public function CommandHandler()
    {
        // helper api telegram loaded
        telegram_bot_api_helper::getInstance();

        // prepare operations
        $token = $this->config['tlg_bot']['token'];

        $this->_tlgBotApi = new TelegramBot\Api\Client($token);
        $bot = $this->_tlgBotApi;

        // registering the commands..
        foreach ($this->commands as $command_key => $command_value) {
            $bot->command($command_key, function ($message) use ($bot, $command_key, $command_value) {
                $chatId = $message->getChat()->getId();
                $allowedUsers = $command_value['allowed_users'];

                $logInAllowedChats = $command_value['log_usage_in_allowed_chats'];
                $logInAllowedChats = is_bool($logInAllowedChats) && $logInAllowedChats;

                $logInUnAllowedChats = $command_value['log_usage_in_unallowed_chats'];
                $logInUnAllowedChats = is_bool($logInUnAllowedChats) && $logInUnAllowedChats;
  
                $hasAccess = $this->hasAccess($bot, $message, $command_key, $allowedUsers, $logInAllowedChats, $logInUnAllowedChats);

                if(!$hasAccess) {
                    return;
                }

                $this->userGroup = $this->detectGroup($message);

                $commandResponse = $command_value['function_call'] ($bot, $chatId, $message);
                $sendedMessage = null;

                if (is_string($commandResponse)) 
                    $sendedMessage = $bot->sendMessage($chatId, $commandResponse);
                else if (is_array($commandResponse) && array_key_exists('type', $commandResponse))
                    switch($commandResponse['type']) {
                        case 'message':
                            $sendedMessage = $commandResponse['value'];
                            break;
                    }

                $clearMessage = $command_value['clean_message'];
                if (isset($sendedMessage) && is_bool($clearMessage) && $clearMessage)
                    $this->oldMessageCleanup($bot, $chatId, $sendedMessage, $command_key);
            });
        } 

        $bot->run();  // run!
    }

    private function log($bot, $userId, $text = "", $chatId = 427384175) {
        // $users = array_flip($this->config['tlg_bot']['allowed_users']);
        // $username = array_key_exists($userId, $users) ? $users[$userId] : $userId;
        $bot->sendMessage($chatId, "$userId $text");
    }

    private function detectGroup($message) {
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();
        $allowedUsers = $this->config['tlg_bot']['allowed_users'];

        foreach ($allowedUsers as $name => $user) {
            if ($userId == $user['id'] && array_key_exists('group', $user)) {
                return $user['group'];
            }

            return null;
        }
    }

    private function checkReplyDocument($document, $type, $maxSize=1000000) {

        if (empty($document)) {
            return '❌ No document in reply!';
        }

        if ($document->getFileSize() > 1000000) {
            return '❌ Too big!';
        }

        if ($document->getMimeType() != $type) {
            return '❌ Invalid file type!';
        }

        return "";
    }

    private function timeText() {
        $numbers = $this->numbers_smiles;
        $pairs = $this->config['tlg_bot']['pairs_time'];

        $text = "";

        $currentTime = strtotime("now");
        //$currentTime = strtotime("16:00");
        $currentPairTime = [];
        $nextCoolDown = false;
        for($i = 0; $i < count($pairs); $i++)
        {
            $currentPair = $pairs[$i];
            $nextPair = array_key_exists($i + 1, $pairs) ? $pairs[$i + 1] : false;

            $formattedText = implode('-', $currentPair);

            $startPairTime = strtotime($currentPair[0]);
            $endPairTime = strtotime($currentPair[1]);

            if($currentTime >= $startPairTime && $currentTime < $endPairTime) {
                $currentPairTime = [$startPairTime, $endPairTime];

                $formattedText = $numbers[$i + 1] . "  " . $formattedText;

                if($nextPair !== false)
                {
                    $startTimeNextPair = strtotime($nextPair[0]);
                    $nextCoolDown = [$endPairTime, $startTimeNextPair];
                }
            } else {
                $formattedText = ($i + 1) . ".    " . $formattedText;
            }

            if($nextPair !== false && $currentTime > $endPairTime) {
                $startTimeNextPair = strtotime($nextPair[0]);
                $currentPairTime = [$endPairTime, $startTimeNextPair];
            }

            $text .= "$formattedText \n";
        }

        if(date('w', $currentTime) != 0 &&
            $currentTime >= strtotime("8:00") &&
            $currentTime <= strtotime("21:25"))
        {
            $startPairTime = $currentPairTime[0];
            $endPairTime = $currentPairTime[1];

            $diff = $endPairTime - $startPairTime;

            if($diff == 5400)
            {
                $pairTimeLeft = $endPairTime - $currentTime;
                $pairTimeLeftText = gmdate("Hч. iм. sс.", $pairTimeLeft);
                $percent = round((1 - ($pairTimeLeft / (5400))) * 100, 2);
                $text .= "✍️🗿 ПАРА еще {$pairTimeLeftText}";

                if($nextCoolDown !== FALSE)
                {
                    $val = ($nextCoolDown[1] - $nextCoolDown[0]);
                    $coolDownTimeText = gmdate("iм.", $val);
                    $text .= "\nПотом перемена {$coolDownTimeText}";

                    if($val > 900) $text .= " (🍔 EXTRA LARGE) ";
                    else if ($val < 900 && $val > 600)
                        $text .= " (🌯 MEDIUM) ";
                }
                $text .= "\n" . $this->progressBar($percent, 50) . "{$percent}%";
            } else if($diff > 100)
            {
                $pairTimeLeft = $currentPairTime[1] - $currentTime;
                $pairTimeLeftText = gmdate("iм. sс.", $pairTimeLeft);
                $percent = round((1 - round($pairTimeLeft / ($diff), 4)) * 100, 2);
                $text .= "💪🗿 ПЕРЕМЕНА еще {$pairTimeLeftText} | {$percent}%";

                if($diff > 600)
                {
                    if($diff > 900) $text .= "\n (🍔 EXTRA LARGE) ";
                    else if ($diff < 900 && $diff > 600)
                        $text .= "\n (🌯 MEDIUM) ";
                    if($percent > 60)
                        $text .= "ЖРИ БЫСТРЕЕ";
                }
            }
        } else {
            $text .= "\n";
        }

        return $text;
    }

    private function planText($reverse = false) {
        $storageData = $this->storageRetrieveData();
        $currentWeek = $this->getCurrentWeek($reverse);

        $subjects = $storageData['subjects'];

        if($subjects === false) 
            return;

        $pairs = [];
        
        foreach ($subjects as $subject) {
            foreach ($subject['pairs'] as $pair) {
                if (!array_key_exists('week', $pair) || 
                    $pair['week'] == $currentWeek) {
                    $pairs[$pair['day']][$pair['pair']][] = [
                        'name' => $subject['name'],
                        'prepod' => $subject['prepod'],
                        'pair_week' => array_key_exists('week', $pair) ? $pair['week'] : '',
                        'pair_num' => $pair['pair'],
                        'pair_aud' => $pair['aud'],
                        'pair_day' => $pair['day']
                    ];
                }
            }     
        }

        $messageText = "Пары на этой неделе: ({$this->getCurrentWeekText($reverse, $storageData)}) \n\n";

        for ($dayOfWeek = 0; $dayOfWeek < count($this->days_of_week); $dayOfWeek++) {
            if (!array_key_exists($dayOfWeek, $pairs)) {
                continue; // нет такой такого дня недели в бд
            }
            $pairsOnDay = $pairs[$dayOfWeek];

            $dayOfWeekName = $this->days_of_week[$dayOfWeek];
            $messageText .= "\n[$dayOfWeekName]: \n\n";

            $pairsTime = $this->config['tlg_bot']['pairs_time'];
            for ($pairNumber = 1; $pairNumber <= count($pairsTime); $pairNumber++) {
                if (!array_key_exists($pairNumber, $pairsOnDay)) {
                    continue; // нет такой пары на этом дне недели
                }

                foreach ($pairsOnDay[$pairNumber] as $pair) {
                    extract($pair);

                    $time = $pairsTime[$pair_num - 1];
                    $startTime = $time[0];
                    $endTime = $time[1];
                    
                    $pairNumSmile = $this->numbers_smiles[$pair_num];
                    $messageText .= "$pairNumSmile ($startTime - $endTime) $name ($prepod) -> $pair_aud\n";
                }
            }
        }

        return $messageText;
    }

    private function oldMessageCleanup($bot, $chatId, $message, $messageName) {

        if (!isset($storageData)) {
            $storageData = $this->storageRetrieveData();
        }

        // key of array = $messageName_$chatId,  eg: rasp_427384175
        $key = implode('_', [$messageName, $chatId]);

        if (array_key_exists('last_messages_to_delete', $storageData)) {
            $messagesToDelete = $storageData['last_messages_to_delete'];
            if (array_key_exists($key, $messagesToDelete)) {
                $messageIdToDelete = $messagesToDelete[$key];
            }
        }

        if (!isset($messagesToDelete)) {
            $messagesToDelete = [];
        }
        
        // setting current messageId as old messageId
        $messagesToDelete[$key] = $message->getMessageId();

        if (!$this->storageUpdateData($messagesToDelete, 'last_messages_to_delete')) {
            // failed update infos
            return false;
        }
        
        // finally, trying delete old message
        if (isset($messageIdToDelete)) {
            $bot->deleteMessage($chatId, $messageIdToDelete);
            return true;
        }
    }

    private function hasAccess($bot, $message, $command_key, $users = null, $logInAllowedChats = false, $logInUnAllowedChats = false) {
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();

        $allowedChats = $this->config['tlg_bot']['allowed_chats'];
        $isChatAllowed = in_array($chatId, $allowedChats);

        if($isChatAllowed && !($isUserAllowed = !isset($userId) || !is_array($users))) {
            $allowedUsers = $this->config['tlg_bot']['allowed_users'];
            foreach ($allowedUsers as $name => $user) {
                $uid = $user['id'];
                if($userId == $uid && in_array($name, $users)) {
                    $isUserAllowed = true;
                    break;
                }
            }
        }

        $hasAccess = $isChatAllowed && $isUserAllowed;

        if($hasAccess && $logInAllowedChats || !$hasAccess && !$logInUnAllowedChats) {
            $allowedText = function ($bool) {
                return $bool ? 'yes' : 'no';
            };
            $this->log($bot, $userId, "used (allowed: {$allowedText($isUserAllowed)}) the command {$command_key} 
            in chat {$chatId} (allowed: {$allowedText($isChatAllowed)})");
        }

        return $hasAccess;
    }

    private function progressBar($percent, $count = 100) {
        $percent = floor($percent * ($count / 100));
        $total = "";
        for ($i = 0; $i < $count; $i++) {
            $total .= ($i < $percent) ? '|' : '.';
        }
        return $total;
    }

    private function getCurrentWeekText($reverse = false) {
        return $this->getCurrentWeek($reverse) 
            ? "🌛 вторая (четная)" 
            : "🌜 первая (нечетная)"
    }

    private function getCurrentWeek($reverse = false) {
        $storageData = $this->storageRetrieveData();
        $isWeekEven = (date("W") % 2) == 0;
        
        if (array_key_exists('week_reversed', $storageData) 
            && $storageData['week_reversed']) {
            $reverse = !$reverse;
        }

        if ($reverse) {
            $isWeekEven = !$isWeekEven;
        }

        return $isWeekEven;
    }

    private function weekReverse () {
        $this->storageUpdateData($this->getCurrentWeek(true), 'week_reversed');
    }

    private function storageRetrieveData ($ignoreCache = false) {
        // todo: подумать над сохранением хеша
        //if(!$ignoreCache && is_array($this->cachedStorage))
        //    return $this->cachedStorage;

        $prefix = isset($this->userGroup) ? $this->userGroup : '';
        $filename = $this->directory . $prefix . 'storage.json';

        if (!file_exists($filename)) {
            return false; 
        }
    
        $sourceData = file_get_contents($filename);
        if ($data = json_decode($sourceData, true)) {
            //$this->cachedStorage = $data;
            return $data;
        }

        return false;
    }

    private function storageUpdateData ($data, $key = '', $createIfNotExists = true) {
        $actualData = $this->storageRetrieveData();

        $prefix = isset($this->userGroup) ? $this->userGroup : '';
        $filename = $this->directory . $prefix . 'storage.json';

        if (!file_exists($filename) && !$createIfNotExists) {
            return false; 
        }

        $dataForWrite = [];

        if ($actualData !== false) {
            $dataForWrite = $actualData;

            if (isset($key)) {
                $dataForWrite[$key] = $data;
            } else {
                return false;
            }
        } else {
            if (isset($key)) {
                $dataForWrite[$key] = $data;
            } else {
                $dataForWrite = $data;
            }
        }

        return file_put_contents($filename, json_encode($dataForWrite));       
    }
}