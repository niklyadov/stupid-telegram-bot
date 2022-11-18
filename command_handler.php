<?php 

namespace stupid_telegram_bot;

defined('BASE_PATH') OR exit('No direct script access allowed');

/*
    * Stupid telegram bot CommandHandler (v.1.0.0) ± 8.09.2021
    * copy., 2021, @Niklyadov
*/

class CommandHandler {
    private $commandFunction = null;

    public function __construct($limitations, $commandFunction)
    {
        $this->commandFunction = $commandFunction;
    }

    public function handle($message) {
        $func = $this->commandFunction;
        $func($message);
    }
}