<?php
declare(strict_types=1);

namespace BaileysPHP\WABinary;

/**
 * Marks a PHP string as raw *bytes* content of a BinaryNode.
 *
 * Why: in Node, BinaryNode content is `string | Buffer | BinaryNode[]` and the
 * encoder treats strings as token/JID strings but Buffers as length-prefixed
 * raw bytes. PHP has only one string type, so raw bytes must be wrapped:
 *   new BinaryNode('enc', [], new Bytes($ciphertext))
 *   new BinaryNode('text', [], 'hello')          // string content
 */
final class Bytes
{
    public function __construct(public string $data) {}

    public function __toString(): string
    {
        return $this->data;
    }
}

