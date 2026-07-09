<?php

namespace Gb28181Gateway\src\SwooleServer;

class SimpleLogger
{
    public function debug(string $msg) : void
    {
        $this->write('DEBUG', $msg);
    }

    public function info(string $msg) : void
    {
        $this->write('INFO', $msg);
    }

    public function error(string $msg) : void
    {
        $this->write('ERROR', $msg);
    }

    private function write(string $level, string $msg) : void
    {
        $ts = date('Y-m-d H:i:s');
        fwrite(STDOUT, "[{$ts}][{$level}] {$msg}\n");
    }
}