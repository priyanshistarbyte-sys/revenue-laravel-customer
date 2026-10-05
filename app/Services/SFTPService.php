<?php

namespace App\Services;

use phpseclib3\Net\SFTP;
use Exception;

class SFTPService
{
    protected ?SFTP $sftp = null;

    protected $host;
    protected $port;
    protected $username;
    protected $password;

    /** Credentials from the selected server ($cfg), falling back to .env. */
    public function __construct(array $cfg = [])
    {
        $this->host     = $cfg['ssh_host']     ?? env('SSH_HOST');
        $this->port     = $cfg['ssh_port']     ?? env('SSH_PORT');
        $this->username = $cfg['ssh_username'] ?? env('SSH_USERNAME');
        $this->password = $cfg['ssh_password'] ?? env('SSH_PASSWORD');
    }

    public function connect()
    {
        if ($this->sftp !== null) {
            return $this->sftp;
        }

        $this->sftp = new SFTP($this->host, $this->port);

        if (!$this->sftp->login($this->username, $this->password)) {
            $this->sftp = null;
            throw new Exception('SFTP Login Failed');
        }

        return $this->sftp;
    }

    /** Write a string directly to a remote file (used for small generated files like ads.txt). */
    public function putContents($remoteFile, $contents)
    {
        $this->connect();

        $result = $this->sftp->put($remoteFile, $contents); // SOURCE_STRING (default)

        if ($result === false) {
            throw new Exception(
                'SFTP write failed for ' . $remoteFile . '. SFTP error: ' . $this->sftp->getLastSFTPError()
            );
        }

        return $result;
    }

    /** Delete a remote file if it exists (no error when it's already absent). */
    public function deleteIfExists($remoteFile)
    {
        $this->connect();

        if ($this->sftp->file_exists($remoteFile)) {
            $this->sftp->delete($remoteFile, false);
        }
    }

    /**
     * Make sure a remote directory exists (creating parents as needed), so an
     * upload never fails with NO_SUCH_FILE just because the site folder hasn't
     * been created yet. Safe to call when the directory already exists.
     */
    public function ensureDir($remoteDir)
    {
        $this->connect();

        if ($this->sftp->is_dir($remoteDir)) {
            return true;
        }

        // phpseclib mkdir with recursive=true creates any missing parent dirs.
        if (!$this->sftp->mkdir($remoteDir, -1, true) && !$this->sftp->is_dir($remoteDir)) {
            throw new Exception(
                'Could not create remote directory ' . $remoteDir . '. SFTP error: ' . $this->sftp->getLastSFTPError()
            );
        }

        return true;
    }

    public function upload($localFile, $remoteFile)
    {
        $this->connect();

        $this->ensureDir(dirname($remoteFile));

        $result = $this->sftp->put(
            $remoteFile,
            $localFile,
            SFTP::SOURCE_LOCAL_FILE
        );

        if (!$result) {
            throw new Exception(
                'SFTP upload failed. SFTP error: ' . $this->sftp->getLastSFTPError()
            );
        }

        $stat = $this->sftp->stat($remoteFile);
        $remoteSize = $stat['size'] ?? false;
        if ($remoteSize === false || $remoteSize === 0) {
            throw new Exception("SFTP upload verification failed: {$remoteFile} is missing or empty on the server.");
        }

        return $result;
    }
}