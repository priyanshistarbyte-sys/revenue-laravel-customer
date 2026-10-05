<?php

namespace App\Services;

use phpseclib3\Net\SSH2;
use Exception;

class SSHService
{
    protected ?SSH2 $ssh = null;

    protected $host;
    protected $port;
    protected $username;
    protected $password;

    /**
     * Credentials come from the selected server (passed in as $cfg). When a key
     * is missing it falls back to the .env values, so callers that don't pass a
     * server still work exactly as before.
     */
    public function __construct(array $cfg = [])
    {
        $this->host     = $cfg['ssh_host']     ?? env('SSH_HOST');
        $this->port     = $cfg['ssh_port']     ?? env('SSH_PORT');
        $this->username = $cfg['ssh_username'] ?? env('SSH_USERNAME');
        $this->password = $cfg['ssh_password'] ?? env('SSH_PASSWORD');
    }

    /**
     * Reuses the existing connection if we already have one instead
     * of opening a brand-new SSH session (with a full login
     * handshake) on every single command. A DeploymentService
     * instance lives for one request, so this connection gets
     * reused across every ssh->execute() call in that request.
     */
    public function connect()
    {
        if ($this->ssh !== null) {
            return $this->ssh;
        }

        $this->ssh = new SSH2($this->host, $this->port);

        if (!$this->ssh->login($this->username, $this->password)) {
            $this->ssh = null;
            throw new Exception('SSH Login Failed.');
        }

        // Deploy steps (acme.sh install + Let's Encrypt issuance, unzip, etc.) can
        // run well past phpseclib's default 10s exec timeout — give them room.
        $this->ssh->setTimeout(600);

        return $this->ssh;
    }

    /**
     * Execute a remote command and THROW if it fails, instead of
     * silently returning whatever text the shell printed.
     *
     * @throws Exception with the real stderr/stdout from the server
     */
    public function execute($command)
    {
        $this->connect();

        // Defensive: strip Windows-style carriage returns.
        $command = str_replace("\r", '', $command);

        $output = $this->ssh->exec($command);
        $exitStatus = $this->ssh->getExitStatus();

        if ($exitStatus !== 0) {
            throw new Exception(
                "Remote command failed (exit code {$exitStatus}).\n" .
                "Command: {$command}\n" .
                "Output: {$output}"
            );
        }

        return $output;
    }
}