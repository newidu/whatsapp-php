<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

/** Noise transport-mode AES-256-GCM state (see NoiseHandler). */
final class TransportState
{
    private int $readCounter = 0;
    private int $writeCounter = 0;
    private string $iv;

    public function __construct(private string $encKey, private string $decKey)
    {
        $this->iv = str_repeat("\0", 12);
    }

    private function ivFor(int $c): string
    {
        $iv = $this->iv;
        $iv[8] = chr(($c >> 24) & 0xff);
        $iv[9] = chr(($c >> 16) & 0xff);
        $iv[10] = chr(($c >> 8) & 0xff);
        $iv[11] = chr($c & 0xff);
        return $iv;
    }

    public function encrypt(string $plaintext): string
    {
        return aesEncryptGCM($plaintext, $this->encKey, $this->ivFor($this->writeCounter++), '');
    }

    public function decrypt(string $ciphertext): string
    {
        return aesDecryptGCM($ciphertext, $this->decKey, $this->ivFor($this->readCounter++), '');
    }
}
