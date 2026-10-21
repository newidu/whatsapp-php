<?php
declare(strict_types=1);

namespace BaileysPHP\WABinary;

/** Port of Baileys: src/WABinary/generic-utils.ts */
final class GenericUtils
{
    /** @return list<BinaryNode> */
    public static function getBinaryNodeChildren(?BinaryNode $node, string $childTag): array
    {
        if ($node === null || !is_array($node->content)) {
            return [];
        }
        return array_values(array_filter($node->content, fn($c) => $c instanceof BinaryNode && $c->tag === $childTag));
    }

    public static function getBinaryNodeChild(?BinaryNode $node, string $childTag): ?BinaryNode
    {
        return self::getBinaryNodeChildren($node, $childTag)[0] ?? null;
    }

    /** @return list<BinaryNode> */
    public static function getAllBinaryNodeChildren(BinaryNode $node): array
    {
        return is_array($node->content) ? $node->content : [];
    }

    public static function getBinaryNodeChildBuffer(?BinaryNode $node, string $childTag): ?string
    {
        $child = self::getBinaryNodeChild($node, $childTag)?->content;
        if ($child instanceof Bytes) {
            return $child->data;
        }
        return null;
    }

    public static function getBinaryNodeChildString(?BinaryNode $node, string $childTag): ?string
    {
        $child = self::getBinaryNodeChild($node, $childTag)?->content;
        if ($child instanceof Bytes) {
            return $child->data;
        }
        return is_string($child) ? $child : null;
    }

    public static function getBinaryNodeChildUInt(?BinaryNode $node, string $childTag, int $length): ?int
    {
        $buff = self::getBinaryNodeChildBuffer($node, $childTag);
        if ($buff !== null) {
            return self::bufferToUInt($buff, $length);
        }
        return null;
    }

    public static function bufferToUInt(string $e, int $t): int
    {
        $a = 0;
        for ($i = 0; $i < $t; $i++) {
            $a = 256 * $a + ord($e[$i]);
        }
        return $a;
    }

    public static function assertNodeErrorFree(BinaryNode $node): void
    {
        $err = self::getBinaryNodeChild($node, 'error');
        if ($err !== null) {
            throw new \RuntimeException(
                ($err->attrs['text'] ?? 'Unknown error') . ' (code ' . ($err->attrs['code'] ?? '?') . ')',
                (int)($err->attrs['code'] ?? 0)
            );
        }
    }

    /** @param BinaryNode|string|Bytes|list<BinaryNode>|null $node */
    public static function binaryNodeToString(mixed $node, int $i = 0): string
    {
        $tabs = str_repeat("\t", $i);
        if ($node === null) {
            return '';
        }
        if (is_string($node)) {
            return $tabs . $node;
        }
        if ($node instanceof Bytes) {
            return $tabs . bin2hex($node->data);
        }
        if (is_array($node)) {
            return implode("\n", array_map(fn($x) => str_repeat("\t", $i + 1) . self::binaryNodeToString($x, $i + 1), $node));
        }
        /** @var BinaryNode $node */
        $children = self::binaryNodeToString($node->content, $i + 1);
        $attrs = [];
        foreach ($node->attrs as $k => $v) {
            $attrs[] = "$k='$v'";
        }
        $tag = "<{$node->tag} " . implode(' ', $attrs);
        return $tag . ($children !== '' ? ">\n$children\n{$tabs}</{$node->tag}>" : '/>');
    }
}
