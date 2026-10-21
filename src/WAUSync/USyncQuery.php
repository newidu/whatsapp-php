<?php
declare(strict_types=1);

namespace BaileysPHP\WAUSync;

use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\GenericUtils;

/**
 * Minimal port of Baileys WAUSync (USyncQuery + Device/LID/Contact protocols).
 * A query is: list of users + list of protocol names ('devices','lid','contact').
 */
final class USyncQuery
{
    /** @var list<string> */
    public array $protocols = [];
    /** @var list<array{id:?string,phone:?string,lid:?string}> */
    public array $users = [];
    public string $context = 'interactive';
    public string $mode = 'query';

    public function withDeviceProtocol(): self { $this->protocols[] = 'devices'; return $this; }
    public function withLIDProtocol(): self { $this->protocols[] = 'lid'; return $this; }
    public function withContactProtocol(): self { $this->protocols[] = 'contact'; return $this; }
    public function withStatusProtocol(): self { $this->protocols[] = 'status'; return $this; }
    public function withDisappearingModeProtocol(): self { $this->protocols[] = 'disappearing_mode'; return $this; }
    public function withUsernameProtocol(): self { $this->protocols[] = 'username'; return $this; }
    public function withBotProfileProtocol(): self { $this->protocols[] = 'bot'; return $this; }

    /** USyncUser::withId shortcut */
    public static function user(string $id): array { return ['id' => $id]; }
    public function withContext(string $c): self { $this->context = $c; return $this; }
    public function withMode(string $m): self { $this->mode = $m; return $this; }

    public function withUser(array $user): self
    {
        $this->users[] = [
            'id' => $user['id'] ?? null,
            'phone' => $user['phone'] ?? null,
            'lid' => $user['lid'] ?? null,
            'username' => $user['username'] ?? null,
            'usernameKey' => $user['usernameKey'] ?? null,
            'type' => $user['type'] ?? null,
            'personaId' => $user['personaId'] ?? null,
        ];
        return $this;
    }

    private function queryElement(string $protocol): BinaryNode
    {
        return match ($protocol) {
            'devices' => new BinaryNode('devices', ['version' => '2']),
            'lid' => new BinaryNode('lid'),
            'contact' => new BinaryNode('contact'),
            'status' => new BinaryNode('status'),
            'disappearing_mode' => new BinaryNode('disappearing_mode'),
            'username' => new BinaryNode('username'),
            'bot' => new BinaryNode('bot', [], [new BinaryNode('profile', ['v' => '1'])]),
            default => new BinaryNode($protocol),
        };
    }

    private function userElement(string $protocol, array $user): ?BinaryNode
    {
        return match ($protocol) {
            'lid' => !empty($user['lid']) ? new BinaryNode('lid', ['jid' => $user['lid']]) : null,
            'contact' => $this->contactUserElement($user),
            'bot' => new BinaryNode('bot', [], [new BinaryNode('profile', ['persona_id' => (string)($user['personaId'] ?? '')])]),
            default => null,
        };
    }

    public function buildNode(string $sid): BinaryNode
    {
        $userNodes = [];
        foreach ($this->users as $user) {
            $content = [];
            foreach ($this->protocols as $p) {
                $el = $this->userElement($p, $user);
                if ($el !== null) {
                    $content[] = $el;
                }
            }
            $attrs = [];
            if (empty($user['phone']) && !empty($user['id'])) {
                $attrs['jid'] = $user['id'];
            }
            $userNodes[] = new BinaryNode('user', $attrs, $content === [] ? null : $content);
        }
        return new BinaryNode('iq', ['to' => 's.whatsapp.net', 'type' => 'get', 'xmlns' => 'usync'], [
            new BinaryNode('usync', [
                'context' => $this->context,
                'mode' => $this->mode,
                'sid' => $sid,
                'last' => 'true',
                'index' => '0',
            ], [
                new BinaryNode('query', [], array_map(fn($p) => $this->queryElement($p), $this->protocols)),
                new BinaryNode('list', [], $userNodes),
            ]),
        ]);
    }

    /** USyncDeviceProtocol.parser */
    public static function parseDevices(BinaryNode $node): array
    {
        $deviceList = [];
        $keyIndex = null;
        if ($node->tag === 'devices') {
            GenericUtils::assertNodeErrorFree($node);
            $listNode = GenericUtils::getBinaryNodeChild($node, 'device-list');
            if ($listNode !== null && is_array($listNode->content)) {
                foreach ($listNode->content as $child) {
                    if ($child->tag === 'device') {
                        $deviceList[] = [
                            'id' => (int)($child->attrs['id'] ?? 0),
                            'keyIndex' => isset($child->attrs['key-index']) ? (int)$child->attrs['key-index'] : null,
                            'isHosted' => ($child->attrs['is_hosted'] ?? '') === 'true',
                        ];
                    }
                }
            }
            $kiNode = GenericUtils::getBinaryNodeChild($node, 'key-index-list');
            if ($kiNode !== null) {
                $keyIndex = [
                    'timestamp' => (int)($kiNode->attrs['ts'] ?? 0),
                    'signedKeyIndex' => $kiNode->contentString(),
                    'expectedTimestamp' => isset($kiNode->attrs['expected_ts']) ? (int)$kiNode->attrs['expected_ts'] : null,
                ];
            }
        }
        return ['deviceList' => $deviceList, 'keyIndex' => $keyIndex];
    }

    private function contactUserElement(array $user): BinaryNode
    {
        if (!empty($user['phone'])) {
            return new BinaryNode('contact', [], (string)$user['phone']);
        }
        if (!empty($user['username'])) {
            $attrs = ['username' => $user['username']];
            if (!empty($user['usernameKey'])) {
                $attrs['pin'] = $user['usernameKey'];
            }
            if (!empty($user['lid'])) {
                $attrs['lid'] = $user['lid'];
            }
            return new BinaryNode('contact', $attrs);
        }
        if (!empty($user['type'])) {
            return new BinaryNode('contact', ['type' => $user['type']]);
        }
        return new BinaryNode('contact');
    }

    /** USyncStatusProtocol.parser @return ?array{status:?string,setAt:int} */
    public static function parseStatus(BinaryNode $node): ?array
    {
        if ($node->tag !== 'status') {
            return null;
        }
        GenericUtils::assertNodeErrorFree($node);
        $status = $node->contentString();
        if (!$status) {
            $status = (($node->attrs['code'] ?? '') === '401') ? '' : null;
        }
        return ['status' => $status, 'setAt' => (int)($node->attrs['t'] ?? 0)];
    }

    /** USyncDisappearingModeProtocol.parser @return ?array{duration:int,setAt:int} */
    public static function parseDisappearingMode(BinaryNode $node): ?array
    {
        if ($node->tag !== 'disappearing_mode') {
            return null;
        }
        GenericUtils::assertNodeErrorFree($node);
        return [
            'duration' => (int)($node->attrs['duration'] ?? 0),
            'setAt' => (int)($node->attrs['t'] ?? 0),
        ];
    }

    /** USyncBotProfileProtocol.parser */
    public static function parseBotProfile(BinaryNode $userNode): array
    {
        $botNode = GenericUtils::getBinaryNodeChild($userNode, 'bot');
        $profile = GenericUtils::getBinaryNodeChild($botNode, 'profile');
        $commandsNode = GenericUtils::getBinaryNodeChild($profile, 'commands');
        $promptsNode = GenericUtils::getBinaryNodeChild($profile, 'prompts');
        $commands = [];
        foreach (GenericUtils::getBinaryNodeChildren($commandsNode, 'command') as $command) {
            $commands[] = [
                'name' => GenericUtils::getBinaryNodeChildString($command, 'name'),
                'description' => GenericUtils::getBinaryNodeChildString($command, 'description'),
            ];
        }
        $prompts = [];
        foreach (GenericUtils::getBinaryNodeChildren($promptsNode, 'prompt') as $prompt) {
            $prompts[] = GenericUtils::getBinaryNodeChildString($prompt, 'emoji') . ' ' . GenericUtils::getBinaryNodeChildString($prompt, 'text');
        }
        return [
            'isDefault' => GenericUtils::getBinaryNodeChild($profile, 'default') !== null,
            'jid' => $userNode->attrs['jid'] ?? null,
            'name' => GenericUtils::getBinaryNodeChildString($profile, 'name'),
            'attributes' => GenericUtils::getBinaryNodeChildString($profile, 'attributes'),
            'description' => GenericUtils::getBinaryNodeChildString($profile, 'description'),
            'category' => GenericUtils::getBinaryNodeChildString($profile, 'category'),
            'personaId' => $profile?->attrs['persona_id'] ?? null,
            'commandsDescription' => GenericUtils::getBinaryNodeChildString($commandsNode, 'description'),
            'commands' => $commands,
            'prompts' => $prompts,
        ];
    }

    /**
     * @return null|array{list:list<array<string,mixed>>,sideList:array}
     */
    public function parseResult(?BinaryNode $result): ?array
    {
        if ($result === null || ($result->attrs['type'] ?? '') !== 'result') {
            return null;
        }
        $out = ['list' => [], 'sideList' => []];
        $usyncNode = GenericUtils::getBinaryNodeChild($result, 'usync');
        $listNode = $usyncNode ? GenericUtils::getBinaryNodeChild($usyncNode, 'list') : null;
        if ($listNode !== null && is_array($listNode->content)) {
            foreach ($listNode->content as $node) {
                $id = $node->attrs['jid'] ?? null;
                if (!$id) {
                    continue;
                }
                $data = ['id' => $id];
                if (is_array($node->content)) {
                    foreach ($node->content as $child) {
                        $data[$child->tag] = match ($child->tag) {
                            'devices' => self::parseDevices($child),
                            'lid' => $child->attrs['val'] ?? null,
                            'contact' => (function () use ($child) {
                                GenericUtils::assertNodeErrorFree($child);
                                return ($child->attrs['type'] ?? '') === 'in';
                            })(),
                            'status' => self::parseStatus($child),
                            'disappearing_mode' => self::parseDisappearingMode($child),
                            'username' => (function () use ($child) {
                                GenericUtils::assertNodeErrorFree($child);
                                return $child->contentString();
                            })(),
                            'bot' => self::parseBotProfile($node),
                            default => null,
                        };
                        if ($data[$child->tag] === null && !in_array($child->tag, ['lid', 'username'], true)) {
                            if (!in_array($child->tag, ['devices', 'contact', 'status', 'disappearing_mode', 'bot'], true)) {
                                unset($data[$child->tag]);
                            }
                        }
                    }
                }
                $out['list'][] = $data;
            }
        }
        return $out;
    }
}
