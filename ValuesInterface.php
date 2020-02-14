<?php declare(strict_types=1);

interface ValuesInterface
{
    Const USER = "debian";
    Const PASSWORD = "temppwd";

    Const DEFAULT_SOURCE_URI = "127.0.0.1:22";
    Const DEFAULT_DESTINATION_URI = "192.168.7.1:20000";

    Const CLIENT_WRITE_SLEEP = 0.2; // Seconds to wait all data 
    Const SSH_LIFE_TIME = 300; // Seconds to wait without receive communication before disconnect ssh
    Const SSH_READ_TIME_OUT = 0.5; // Seconds to "sleep" before next ssh stream read
    Const MAX_SSH_WRITE_ERROR_NUMBER = 5; // Trys to write before disconnect & reconnect
    Const PRINT_PACKAGES_COUNT_TIME = 1.0; // Time to print packages count

    Public function now();
}