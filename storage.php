<?php 

namespace stupid_telegram_bot;

defined('BASE_PATH') OR exit('No direct script access allowed');

/*
    * Stupid telegram bot Storage (v.1.0.0) ± 8.09.2021
    * copy., 2021, @Niklyadov
*/

class Storage {

    private $actualData = null;

    private function getFs() {
        $filename = $this->directory . 'storage.json';
        if (file_exists($filename)) {
            $sourceData = file_get_contents($filename);
            if ($data = json_decode($sourceData, true)) {
                return $data;
            }   
        }

        return false; 
    }

    public function updateFs() {
        $filename = $this->directory . 'storage.json';
        $text = json_encode($this->actualData);

        return file_put_contents($filename, $text); 
    }

    public function get() {

        if ($this->actualData === null) {

            $data = $this->getFs();
            if ($data === false) {
                $data = [];
            }

            $this->actualData = $data;
        }

        return $this->actualData;
    }
}