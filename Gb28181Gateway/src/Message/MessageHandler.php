<?php

namespace Gb28181\GateWay\Message;

use Gb28181\GateWay\Message\CommandType\CommandInterface;

use \SimpleXMLElement;

class MessageHandler
{
    /**
     * @var CommandInterface[]
     */
    private array $commands = [];

    /**
     * Register a command handler
     *
     * @param CommandInterface $command
     * @return void
     */
    public function registerCommand(CommandInterface $command) : void
    {
        $this->commands[$command->getCommandType()] = $command;
    }

    /**
     * Handle an incoming message
     *
     * @param SimpleXMLElement $xml
     * @param string $deviceId
     * @param array $options
     * @return mixed
     */
    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []) : mixed
    {
        $cmdType = (string)($xml->CmdType ?? '');

        if (isset($this->commands[$cmdType])) {
            return $this->commands[$cmdType]->handle($xml, $deviceId, $options);
        }

        throw new \InvalidArgumentException("Unsupported command type: {$cmdType}");
    }

    /**
     * Generate response for a command
     *
     * @param string $cmdType
     * @param array $data
     * @param int $sn
     * @return string
     */
    public function generateResponse(string $cmdType, array $data, int $sn) : string
    {
        if (isset($this->commands[$cmdType])) {
            return $this->commands[$cmdType]->generateResponse($data, $sn);
        }

        throw new \InvalidArgumentException("GenerateResponse Unsupported command type: {$cmdType}");
    }

    /**
     * Get registered commands
     *
     * @return CommandInterface[]
     */
    public function getCommands() : array
    {
        return $this->commands;
    }
}