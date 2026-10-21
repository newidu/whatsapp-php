<?php
declare(strict_types=1);

namespace BaileysPHP\Auth;

/**
 * Baileys KeyPair type: { private: Buffer, public: Buffer }
 * In PHP, "Buffer" is just a (binary) string.
 */
final class KeyPair
{
    public function __construct(
        public string $private,
        public string $public,
    ) {}

    /** @param array{private:string,public:string} $a */
    public static function fromArray(array $a): self
    {
        return new self($a['private'], $a['public']);
    }

    /** @return array{private:string,public:string} */
    public function toArray(): array
    {
        return ['private' => $this->private, 'public' => $this->public];
    }
}
