<?php
declare(strict_types=1);

namespace BaileysPHP\WABinary;

/** Port of Baileys BinaryNode type (src/WABinary/types.ts). */
final class BinaryNode
{
    /**
     * @param array<string,string> $attrs
     * @param null|string|Bytes|list<BinaryNode> $content
     */
    public function __construct(
        public string $tag,
        public array $attrs = [],
        public mixed $content = null,
    ) {}

    /**
     * Convenience constructor mirroring JS object literals:
     * BinaryNode::make('iq', ['to'=>...], [ BinaryNode::make(...) ])
     *
     * @param array<string,string> $attrs
     * @param null|string|Bytes|list<BinaryNode> $content
     */
    public static function make(string $tag, array $attrs = [], mixed $content = null): self
    {
        return new self($tag, $attrs, $content);
    }

    public static function bytes(string $data): Bytes
    {
        return new Bytes($data);
    }

    /** Unwrap content to a raw string if it is Bytes or string, else null. */
    public function contentString(): ?string
    {
        if ($this->content instanceof Bytes) {
            return $this->content->data;
        }
        return is_string($this->content) ? $this->content : null;
    }

    public function __toString(): string
    {
        return GenericUtils::binaryNodeToString($this);
    }
}
