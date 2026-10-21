<?php
declare(strict_types=1);

namespace BaileysPHP\Utils;

use BaileysPHP\Auth\KeyPair;
use BaileysPHP\Defaults;
use BaileysPHP\Proto\WAProto;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Decoder;

/**
 * Port of Baileys: src/Utils/noise-handler.ts
 * Noise_XX_25519_AESGCM_SHA256 with the WA header, then transport mode.
 *
 * PHP difference: the Node version is callback/async. Here decodeFrame()
 * collects and returns decoded frames (string|BinaryNode) synchronously.
 */
final class NoiseHandler
{
    private const IV_LENGTH = 12;

    private string $hash;
    private string $salt;
    private string $encKey;
    private string $decKey;
    private int $counter = 0;
    private bool $sentIntro = false;
    private string $inBytes = '';
    private ?TransportState $transport = null;
    private string $introHeader;
    private Logger $logger;

    public function __construct(
        private KeyPair $ephemeralKeyPair,
        string $noiseHeader,
        ?Logger $logger = null,
        ?string $routingInfo = null,
    ) {
        $this->logger = ($logger ?? new Logger())->child(['class' => 'ns']);
        $data = Defaults::NOISE_MODE;
        $this->hash = strlen($data) === 32 ? $data : sha256($data);
        $this->salt = $this->hash;
        $this->encKey = $this->hash;
        $this->decKey = $this->hash;

        if ($routingInfo !== null && $routingInfo !== '') {
            $intro = 'ED' . chr(0) . chr(1);
            $intro .= chr((strlen($routingInfo) >> 16) & 0xff);
            $intro .= pack('n', strlen($routingInfo) & 0xffff);
            $intro .= $routingInfo . $noiseHeader;
            $this->introHeader = $intro;
        } else {
            $this->introHeader = $noiseHeader;
        }

        $this->authenticate($noiseHeader);
        $this->authenticate($ephemeralKeyPair->public);
    }

    private static function generateIV(int $counter): string
    {
        $iv = str_repeat("\0", self::IV_LENGTH);
        // DataView.setUint32(8, counter) == big-endian uint32 at offset 8
        $iv[8] = chr(($counter >> 24) & 0xff);
        $iv[9] = chr(($counter >> 16) & 0xff);
        $iv[10] = chr(($counter >> 8) & 0xff);
        $iv[11] = chr($counter & 0xff);
        return $iv;
    }

    public function authenticate(string $data): void
    {
        if ($this->transport === null) {
            $this->hash = sha256($this->hash . $data);
        }
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->transport !== null) {
            return $this->transport->encrypt($plaintext);
        }
        $result = aesEncryptGCM($plaintext, $this->encKey, self::generateIV($this->counter++), $this->hash);
        $this->authenticate($result);
        return $result;
    }

    public function decrypt(string $ciphertext): string
    {
        if ($this->transport !== null) {
            return $this->transport->decrypt($ciphertext);
        }
        $result = aesDecryptGCM($ciphertext, $this->decKey, self::generateIV($this->counter++), $this->hash);
        $this->authenticate($ciphertext);
        return $result;
    }

    /** @return array{0:string,1:string} */
    private function localHKDF(string $data): array
    {
        $key = hkdf($data, 64, $this->salt, '');
        return [substr($key, 0, 32), substr($key, 32)];
    }

    public function mixIntoKey(string $data): void
    {
        [$write, $read] = $this->localHKDF($data);
        $this->salt = $write;
        $this->encKey = $read;
        $this->decKey = $read;
        $this->counter = 0;
    }

    public function finishInit(): void
    {
        [$write, $read] = $this->localHKDF('');
        $this->transport = new TransportState($write, $read);
        $this->logger->trace('Noise handler transitioned to Transport state');
    }

    public function isTransport(): bool
    {
        return $this->transport !== null;
    }

    /**
     * @param array{ephemeral:?string,static:?string,payload:?string} $serverHello
     * @return string encrypted static key (keyEnc) for ClientFinish
     */
    public function processHandshake(array $serverHello, KeyPair $noiseKey): string
    {
        $privateKey = $this->ephemeralKeyPair->private;
        $this->authenticate($serverHello['ephemeral'] ?? '');
        $this->mixIntoKey(Curve::sharedKey($privateKey, $serverHello['ephemeral'] ?? ''));

        $decStatic = $this->decrypt($serverHello['static'] ?? '');
        $this->mixIntoKey(Curve::sharedKey($privateKey, $decStatic));

        $certDecoded = $this->decrypt($serverHello['payload'] ?? '');
        $chain = WAProto::decodeCertChain($certDecoded);
        $leaf = $chain['leaf'] ?? null;
        $intermediate = $chain['intermediate'] ?? null;
        if (!$leaf || !$leaf['details'] || !$leaf['signature']) {
            throw new \RuntimeException('invalid noise leaf certificate', 400);
        }
        if (!$intermediate || !$intermediate['details'] || !$intermediate['signature']) {
            throw new \RuntimeException('invalid noise intermediate certificate', 400);
        }
        $details = WAProto::decodeCertDetails($intermediate['details']);
        $verify = Curve::verify($details['key'] ?? '', $leaf['details'], $leaf['signature']);
        $verifyIntermediate = Curve::verify(
            hex2bin(Defaults::WA_CERT_PUBLIC_KEY_HEX),
            $intermediate['details'],
            $intermediate['signature']
        );
        if (!$verify) {
            throw new \RuntimeException('noise certificate signature invalid', 400);
        }
        if (!$verifyIntermediate) {
            throw new \RuntimeException('noise intermediate certificate signature invalid', 400);
        }
        if (($details['issuerSerial'] ?? null) !== Defaults::WA_CERT_SERIAL) {
            throw new \RuntimeException('certification match failed', 400);
        }

        $keyEnc = $this->encrypt($noiseKey->public);
        $this->mixIntoKey(Curve::sharedKey($noiseKey->private, $serverHello['ephemeral'] ?? ''));
        return $keyEnc;
    }

    public function encodeFrame(string $data): string
    {
        if ($this->transport !== null) {
            $data = $this->transport->encrypt($data);
        }
        $intro = '';
        if (!$this->sentIntro) {
            $intro = $this->introHeader;
            $this->sentIntro = true;
        }
        $len = strlen($data);
        $header = chr(($len >> 16) & 0xff) . chr(($len >> 8) & 0xff) . chr($len & 0xff);
        return $intro . $header . $data;
    }

    /**
     * Feed raw WebSocket payload bytes; returns every complete frame decoded
     * from the internal buffer.
     * Before transport: frames are raw strings (handshake).
     * After transport : frames are BinaryNode objects.
     * @return list<string|BinaryNode>
     */
    public function decodeFrame(string $newData): array
    {
        $this->inBytes .= $newData;
        $frames = [];
        while (true) {
            if (strlen($this->inBytes) < 3) {
                break;
            }
            $size = (ord($this->inBytes[0]) << 16) | (ord($this->inBytes[1]) << 8) | ord($this->inBytes[2]);
            if (strlen($this->inBytes) < $size + 3) {
                break;
            }
            $frame = substr($this->inBytes, 3, $size);
            $this->inBytes = (string)substr($this->inBytes, $size + 3);
            if ($this->transport !== null) {
                $plain = $this->transport->decrypt($frame);
                $frames[] = Decoder::decodeBinaryNode($plain);
            } else {
                $frames[] = $frame;
            }
        }
        return $frames;
    }
}
