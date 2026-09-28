<?php
require_once __DIR__.'/send-helpers.php';

final class ZfsasSshConnection
{
    public static function normalize(array $config): array
    {
        $connection=[
            'host'=>zfsas_send_normalize_ssh_host($config['SEND_SSH_HOST'] ?? ''),
            'port'=>zfsas_send_normalize_ssh_port($config['SEND_SSH_PORT'] ?? '22'),
            'user'=>zfsas_send_normalize_ssh_user($config['SEND_SSH_USER'] ?? 'root'),
            'key'=>zfsas_send_normalize_ssh_key_path($config['SEND_SSH_KEY_PATH'] ?? ''),
        ];
        if (in_array(null,$connection,true) || $connection['host']==='') {
            throw new InvalidArgumentException('A valid saved SSH connection is required.');
        }
        return $connection;
    }

    public static function arguments(array $connection, ?string $hostKey=null): array
    {
        $ssh=['ssh','-T',
            '-o','BatchMode=yes','-o','PasswordAuthentication=no','-o','KbdInteractiveAuthentication=no',
            '-o','StrictHostKeyChecking=yes','-o','UpdateHostKeys=no','-o','VerifyHostKeyDNS=no',
            '-o','ControlMaster=no','-o','ControlPath=none','-o','ControlPersist=no',
            '-o','ClearAllForwardings=yes','-o','ForwardAgent=no','-o','ForwardX11=no',
            '-o','ConnectTimeout=10','-o','ConnectionAttempts=1','-o','ServerAliveInterval=5','-o','ServerAliveCountMax=2',
            '-o','FingerprintHash=sha256','-o','PermitLocalCommand=no',
            '-p',$connection['port']];
        if ($hostKey!==null) {
            if (!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D',$hostKey)) { throw new InvalidArgumentException('Captured SSH host identity required.'); }
            // Paths are fixed installation paths, and the fingerprint alphabet
            // contains no shell metacharacters. OpenSSH expands these tokens.
            array_push($ssh,'-o','KnownHostsCommand='.PHP_BINARY.' '.__DIR__.'/ssh-host-pin.php '.$hostKey.' %I %f');
        }
        if ($connection['key']!=='') { array_push($ssh,'-i',$connection['key']); }
        return $ssh;
    }

    public static function target(array $connection): string { return $connection['user'].'@'.$connection['host']; }
}
