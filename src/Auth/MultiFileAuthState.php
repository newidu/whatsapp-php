<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

/**
 * Port of Baileys useMultiFileAuthState(folder):
 *
 *   $auth = MultiFileAuthState::use('auth_info_baileys');
 *   $sock = makeWASocket(['auth' => $auth->state, ...]);
 *   $sock->ev->on('creds.update', fn() => $auth->saveCreds());
 */
final class MultiFileAuthState
{
    public AuthState $state;

    private function __construct(private string $folder, Creds $creds)
    {
        $this->state = new AuthState($creds, new FileSignalKeyStore($folder));
        // let the socket persist creds after internal updates
        $this->state->saveCredsCallback = fn() => $this->saveCreds();
    }

    public static function use(string $folder): self
    {
        if (file_exists($folder) && !is_dir($folder)) {
            throw new \RuntimeException("found something that is not a directory at $folder, either delete it or specify a different location");
        }
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $credsFile = $folder . DIRECTORY_SEPARATOR . 'creds.json';
        $creds = null;
        if (is_file($credsFile)) {
            $raw = file_get_contents($credsFile);
            if ($raw !== false && trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (is_array($json) && isset($json['noiseKey'])) {
                    $creds = Creds::fromJsonArray($json);
                }
            }
        }
        return new self($folder, $creds ?? Creds::init());
    }

    public function saveCreds(): void
    {
        $path = $this->folder . DIRECTORY_SEPARATOR . 'creds.json';
        $tmp = $path . '.tmp' . getmypid();
        file_put_contents($tmp, json_encode($this->state->creds->toJsonArray(), JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $path);
    }
}
