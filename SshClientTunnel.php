<?php declare(strict_types=1);

use BrunoNatali\Tools\OutSystem;
use React\EventLoop\Factory;
use React\Socket\Connector;
use React\Socket\TimeoutConnector;
use React\Socket\ConnectionInterface;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/ValuesInterface.php';

final class SshClientTunnel implements ValuesInterface
{
    Private $loop;
    Private $debug;
    Private $sourceAddr = self::DEFAULT_SOURCE_URI;
    Private $destinationAddr = self::DEFAULT_DESTINATION_URI;

    Private $sshStream;

    Private $opened;
    Private $client;
    Private $clientWriteTimer = null;
    Private $clientBuffer = null;
    Private $debugTimer;
    Private $sshLifeTimer;
    Private $sshAsFtp = false;
    Private $sshReadTimer = null;
    Private $sshWriteErrorCount = 0;
    Private $sshPkgCount = 0;
    Private $sshPkgCountLast = 0;
    Private $clientPkgCount = 0;
    Private $clientPkgCountLast = 0;
    Private $sshTimeToLive = self::SSH_LIFE_TIME;

    function __construct(array $config =[])
    {
        $this->loop = Factory::create();

        $deugOn = false;
        $next = false;
        foreach ($config as $key => $value) {
            if ($next) continue;
            if ($value === '-s') {
                if (is_string($config[$key + 1])) $this->sourceAddr = trim($config[$key + 1]);
                $next = true;
            } else if ($value === '-d') {
                if (is_string($config[$key + 1])) $this->destinationAddr = trim($config[$key + 1]);
                $next = true;
            } else if ($value === '-t') {
                if (is_string($config[$key + 1])) $this->sshTimeToLive = floatval(trim($config[$key + 1]));
                $next = true;
            } else if ($value === '-v') {
                $deugOn = true;
            } else if ($value === '-f') {
                $this->sshAsFtp = true;
            } else if (count($config) > 1 && $key !== 0) {
                echo "\r\nSshClientTunnel HELP\r\n\t-d Destination address (server)\r\n\t-f As FTP (ssh)\r\n\t-h Help\r\n\t-s Source address (ssh)\r\n\t-t Time To Live (ssh)\r\n\t-v Enable debug (verbose)" . PHP_EOL;
                exit;
            }
        }

        $debugConfig = [
            "outSystemName" => 'main',
            "outSystemEnabled" => $deugOn
        ];
        $this->debug = new OutSystem($debugConfig);
    }

    Private function buildSshShellStreamResource()
    {
        $addr = explode(':', $this->sourceAddr);
        if (count($addr) < 2) {
            $this->debug->stdout("Wrong source address: " . $this->sourceAddr);
            return;
        }
        
        if ($this->sshAsFtp) {
            //$this->sshStream = fopen("http://" . $this->sourceAddr, 'r+');
            $this->sshStream = stream_socket_client("tcp://" . $this->sourceAddr, $errno, $errstr, 30);
            stream_set_blocking($this->sshStream, false);
            if (!$this->sshStream)  echo "$errstr ($errno)<br />\n";
        } else {
            $connection = ssh2_connect($addr[ count($addr) -2 ], intval($addr[ count($addr) -1 ]));
            ssh2_auth_password($connection, self::USER, self::PASSWORD);
            $this->sshStream = ssh2_shell($connection, 'xterm');
        }
        $this->debug->stdout("SSH Stream opened");
        $this->sshCountDownToDie(false); // Start auto-close timer
        $this->readSsh();
    }

    Private function buildClientConnector()
    {
        $this->debug->stdout("Connecting to server ... ", false);
        $connector = new Connector($this->loop);
        $connector = new TimeoutConnector($connector, 15.0, $this->loop);

        $me = &$this;
        $connector->connect($this->destinationAddr)->then(function (ConnectionInterface $connection) use (&$me) {
            $connection->on('data', function ($data) use ($me) {
                $me->clientPkgCount += strlen($data);
                $me->writeToSsh($data);
            });
            $connection->on('close', function () use (&$me) {
                $me->debug->stdout("Client connection was lost");
                $me->client = null;
                $me->startDebugging(false);
                $me->loop->addTimer(5.0, function () use (&$me) { // Retry connection
                    $me->buildClientConnector();
                });
            });
            $me->debug->stdout("OK");
            $me->startDebugging();
            $me->client = &$connection;
        }, function ($e) use (&$me) {
            $me->debug->stdout("ERROR : " . $e->getMessage());
            $me->startDebugging(false);
            $me->client = null; // Ensure reset client handler
            $me->loop->addTimer(5.0, function () use (&$me) { // Retry connection
                $me->buildClientConnector();
            });
        });
    }

    Private function closeSshStream()
    {
        if (!is_resource($this->sshStream)) return;
        if (@fclose($this->sshStream) === false) $this->debug->stdout("CRTICAL ERROR - Fail on close ssh resource");
        $this->loop->cancelTimer($this->sshReadTimer);
        $this->sshStream = null;
        $this->debug->stdout("SSH Stream closed");
    }

    Private function recreateSshStream()
    {
        $this->closeSshStream();
        $this->buildSshShellStreamResource();
    }

    Private function writeToSsh($data) 
    {
        $dataLen = strlen($data);

        if ($dataLen === 0) return;
        if (!is_resource($this->sshStream)) $this->buildSshShellStreamResource();

        $this->sshCountDownToDie();
        if ($dataLen === @fwrite($this->sshStream, $data)) return;

        if (++$this->sshWriteErrorCount === self::MAX_SSH_WRITE_ERROR_NUMBER)
            $this->recreateSshStream();
    }

    Private function writeToClient($data, $len)
    {
        $this->sshPkgCount += $len;
        if ($this->client !== null) {
            if ($this->sshAsFtp) { // If is 4 ftp wait more time to consolidate all data 
                $me = &$this;
                $this->clientBuffer .= $data;
                if ($this->clientWriteTimer !== null) $this->loop->cancelTimer($this->clientWriteTimer);
                $this->clientWriteTimer = $this->loop->addTimer(self::CLIENT_WRITE_SLEEP, function () use ($me) {
                    $me->client->write($me->clientBuffer);
                    $me->clientBuffer = null;
                });
                return;
            } else {
                $this->client->write($data);
            }
        }
    }

    Private function readSsh()
    {
        if (!is_resource($this->sshStream)) return;

        $me = &$this;
        if (($buff = @fgets($this->sshStream)) !== false) {
            if (is_string($buff) && ($len = strlen($buff)) !== 0) 
                $this->writeToClient($buff, $len);

            $this->loop->futureTick(function () use ($me) {
                $me->readSsh();
            });
        } else {
            $this->sshReadTimer = $this->loop->addTimer(self::SSH_READ_TIME_OUT, function () use ($me) {
                $me->readSsh();
            });
        }
    }

    Private function sshCountDownToDie(bool $reset = true)
    {
        if ($reset) $this->loop->cancelTimer($this->sshLifeTimer);
        $me = &$this;
        $this->sshLifeTimer = $this->loop->addTimer($this->sshTimeToLive, function () use (&$me) { // Retry connection
            if ($me->client !== null) $me->client->write('ACTIVELY CLOSED SSH CONNECTION (inactivety for ' . $me->sshTimeToLive . 's)');
            $me->closeSshStream();
        });
        
    }

    Private function startDebugging(bool $enable = true)
    {
        if ($enable) {
            $me = &$this;
            $this->debugTimer = $this->loop->addPeriodicTimer(self::PRINT_PACKAGES_COUNT_TIME, function () use (&$me) {
                if ($me->sshPkgCount === $me->sshPkgCountLast && $me->clientPkgCount === $me->clientPkgCountLast)
                    return;

                $me->debug->stdout("\r\n\tSSH: " . $me->sshPkgCount . "\r\n\tServer: " . $me->clientPkgCount);
                $me->sshPkgCountLast = $me->sshPkgCount;
                $me->clientPkgCountLast = $me->clientPkgCount;
            });
        } else {
            $this->loop->cancelTimer($this->debugTimer); 
        }
    }

    Public function now()
    {
        $this->startDebugging(); // Work around React cancelTimer blockUp A
        $this->buildSshShellStreamResource();
        $this->buildClientConnector();
        $this->startDebugging(false); // Work around React cancelTimer blockUp B

        $this->loop->run();
    }
}

$startSshClient = new SshClientTunnel($argv);

$startSshClient->now();