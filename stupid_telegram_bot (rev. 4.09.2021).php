<?php defined('BASE_PATH') OR exit('No direct script access allowed');

/*
    * Stupid telegram bot (v.3.0.0) ± 4.09.2021
    * copy., 2021, @Niklyadov
*/

class module_stupid_telegram_bot extends CsModule
{
    private TelegramBot\Api\Client $_tlgBotApi;

    private $pais_time = [
        ["8:00", "9:30"],
        ["9:40", "11:10"],
        ["11:20", "12:50"],
        ["13:15", "14:45"],
        ["15:00", "16:30"],
        ["16:40", "18:10"],
        ["18:20", "19:50"],
        ["19:55", "21:25"],
    ];

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

    public function OnLoad()
    {
        $this->cubsystem->getRouter()->all("/api/stupid_telegram_bot", [$this, 'CommandHandler']);
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

        $bot->command('week', function ($message) use ($bot) {
            $chatId = $message->getChat()->getId();
            if (!$this->hasAccess($chatId)) return;

            $weeks = [
                0 => "🌜 первая", 
                1 => "🌛 вторая"
            ];

            $currentWeek = $this->getCurrentWeek();

            // смотрим границы текущей недели в дате и переводим в строку
            $monday_of_this_week_time = date("d.m", strtotime('monday this week'));
            $sunday_of_this_week_time = date("d.m", strtotime('sunday this week'));

		    $text = "Щас вроде {$weeks[$currentWeek]} неделя (с {$monday_of_this_week_time} по {$sunday_of_this_week_time})";
            $bot->sendMessage($chatId, $text);
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        $bot->command('weekreverse', function ($message) use ($bot) {
            $chatId = $message->getChat()->getId();
            $userId = $message->getFrom()->getId();
            if (!$this->hasAccess($chatId, $userId, ['nik', 'ser'])) {
                return;
            }

            $currentWeek = $this->getCurrentWeek();
            $this->storageUpdateData($currentWeek == 0 ? 1 : 0, 'week_num');

            $bot->sendMessage($chatId, '✅ ok, week has been reversed');
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        $bot->command('newrasp', function (\TelegramBot\Api\Types\Message $message) use ($bot) {
            $chatId = $message->getChat()->getId();
            $userId = $message->getFrom()->getId();
            if (!$this->hasAccess($chatId, $userId, ['nik', 'ser'])) {
                return;
            }

            $reply = $message->getReplyToMessage();
            if (empty($reply)) {
                $bot->sendMessage($chatId, '❌ Reply only!');
                return;
            }

            $document = $reply->getDocument();
            if (empty($document)) {
                $bot->sendMessage($chatId, '❌ No document in reply!');
                return;
            }

            if ($document->getFileSize() > 1000000) {
                $bot->sendMessage($chatId, '❌ 1MB Max!');
                return;
            }

            if ($document->getMimeType() != "image/png") {
                $bot->sendMessage($chatId, '❌ PNG or JPEG Only!');
                return;
            }

            # Send "Typing..."
            $bot->sendChatAction(
                $message->getChat()->getId(),
                'typing'
            );

            $file = $this->directory . 'rasp.png';

            $token = $this->config['tlg_bot']['token'];
            $filePath = $bot->getFile($document->getFileId())->getFilePath();
            file_put_contents($file, fopen("https://api.telegram.org/file/bot$token/$filePath", 'r'));
            
            $bot->sendMessage($chatId, "Ok. Rasp updated!");
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        $bot->command('newplan', function (\TelegramBot\Api\Types\Message $message) use ($bot) {
            $chatId = $message->getChat()->getId();
            $userId = $message->getFrom()->getId();
            if (!$this->hasAccess($chatId, $userId, ['nik'])) {
                return;
            }

            $reply = $message->getReplyToMessage();
            if (empty($reply)) {
                $bot->sendMessage($chatId, '❌ Reply only!');
                return;
            }

            $document = $reply->getDocument();
            if (empty($document)) {
                $bot->sendMessage($chatId, '❌ No document in reply!');
                return;
            }

            if ($document->getFileSize() > 1000000) {
                $bot->sendMessage($chatId, '❌ 1MB Max!');
                return;
            }

            if ($document->getMimeType() != "application/json") {
                $bot->sendMessage($chatId, '❌ JSON Only!');
                return;
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

                if ($this->storageUpdateData($plan['subjects'], 'subjects', true)) {
                    $bot->sendMessage($chatId, "Ok. New plan has been set");
                    return;
                }
            
                fclose($stream);
            }

            $bot->sendMessage($chatId, "Cannot upload new plan.");
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        $bot->command('time', function ($message) use ($bot) {
            $chatId = $message->getChat()->getId();
            if (!$this->hasAccess($chatId)) return;

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

            $sendMessage = $bot->sendMessage($chatId, "$text");

            $this->oldMessageCleanup($bot, $chatId, $sendMessage, 'time');
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        $bot->command('rasp', function ($message) use ($bot) {
            $chatId = $message->getChat()->getId();
            if(!$this->hasAccess($chatId)) return;

            $file = $this->directory . 'rasp.png';
            $document = new CURLFile($file);
            $sendDocument = $bot->sendDocument($message->getChat()->getId(), $document);

            $this->oldMessageCleanup($bot, $chatId, $sendDocument, 'rasp');
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        $bot->command('plan', function ($message) use ($bot) {
            $chatId = $message->getChat()->getId();
            if (!$this->hasAccess($chatId)) return;

            $storageData = $this->storageRetriveData();

            $subjects = $storageData['subjects'];

            if($subjects === false) 
                return;

            $currentWeek = $this->getCurrentWeek($storageData);

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

            $weeks = [
                0 => "🌜 первая", 
                1 => "🌛 вторая"
            ];

            $messageText = "Пары на этой неделе: ($weeks[$currentWeek]) \n\n";

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

            // sending the message
            $messageSent = $bot->sendMessage($chatId, $messageText);

            // cleanup old message, register current message as old
            $this->oldMessageCleanup($bot, $chatId, $messageSent, 'plan', $storageData);

            // delete command message. ex /plan
            $bot->deleteMessage($chatId, $message->getMessageId());
        });

        // run!

        $bot->run();
    }

    private function oldMessageCleanup($bot, $chatId, $message, $messageName, $storageData = null) {

        if (!isset($storageData)) {
            $storageData = $this->storageRetriveData();
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

    private function hasAccess($chatId, $userId = null, $users = null) {
        $allowedChats = $this->config['tlg_bot']['allowed_chats'];
        if (!in_array($chatId, $allowedChats)) {
            return false;
        }

        if (!isset($userId) && !isset($users)) {
            return true;
        }

        $allowedUsers = $this->config['tlg_bot']['allowed_users'];
        foreach ($allowedUsers as $name => $uid) {
            if (in_array($name, $users)) {
                if ($userId == $uid) {
                    return true;
                }
            }
        }

        return false;
    }

    private function progressBar($percent, $count = 100) {
        $percent = floor($percent * ($count / 100));
        $total = "";
        for ($i = 0; $i < $count; $i++) {
            $total .= ($i < $percent) ? '|' : '.';
        }
        return $total;
    }

    private function getCurrentWeek($storageData = null) {

        if (!isset($storageData)) {
            $storageData = $this->storageRetriveData();
        }

        $week_num = 0;
        if (array_key_exists('week_num', $storageData)) {
            $week_num = intval($storageData['week_num']);
        }

        return ((date("W") + $week_num) % 2) == 0 ? 1 : 0;
    }

    private function storageRetriveData() {
        $filename = $this->directory . 'storage.json';

        if (!file_exists($filename)) {
            return false; 
        }
    
        $sourceData = file_get_contents($filename);
        if ($data = json_decode($sourceData, true)) {
            return $data;
        }

        return false;
    }

    private function storageUpdateData($data, $key = '', $createIfNotExists = true) {
        $actualData = $this->storageRetriveData();

        $filename = $this->directory . 'storage.json';
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