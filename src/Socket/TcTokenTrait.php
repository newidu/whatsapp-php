<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\Bytes;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * Privacy ("trusted contact") tokens — port of the essentials of
 * Baileys src/Utils/tc-token-utils.ts + issuePrivacyTokens (messages-send.ts).
 * Tokens are stored under the `tctoken` auth-store type keyed by storage jid (LID when known).
 */
trait TcTokenTrait
{
    private const TC_TOKEN_BUCKET_DURATION = 604800; // 7 days
    private const TC_TOKEN_NUM_BUCKETS = 4;

    private static function isTcTokenExpired(int|string|null $timestamp): bool
    {
        if ($timestamp === null || $timestamp === '') {
            return true;
        }
        $ts = (int)$timestamp;
        $now = time();
        $currentBucket = intdiv($now, self::TC_TOKEN_BUCKET_DURATION);
        $cutoff = ($currentBucket - (self::TC_TOKEN_NUM_BUCKETS - 1)) * self::TC_TOKEN_BUCKET_DURATION;
        return $ts < $cutoff;
    }

    private static function shouldSendNewTcToken(?int $senderTimestamp): bool
    {
        if ($senderTimestamp === null) {
            return true;
        }
        return intdiv(time(), self::TC_TOKEN_BUCKET_DURATION) > intdiv($senderTimestamp, self::TC_TOKEN_BUCKET_DURATION);
    }

    private function resolveTcTokenJid(string $jid): string
    {
        if (Jid::isLidUser($jid)) {
            return $jid;
        }
        return $this->signalRepository->lidMapping->getLIDForPN($jid) ?? $jid;
    }

    private function resolveIssuanceJid(string $jid): string
    {
        if ($this->serverProps['lidTrustedTokenIssueToLid'] ?? false) {
            if (Jid::isLidUser($jid)) {
                return $jid;
            }
            return $this->signalRepository->lidMapping->getLIDForPN($jid) ?? $jid;
        }
        if (!Jid::isLidUser($jid)) {
            return $jid;
        }
        return $this->signalRepository->lidMapping->getPNForLID($jid) ?? $jid;
    }

    private static function isRegularUserJid(?string $jid): bool
    {
        if (!$jid) {
            return false;
        }
        $user = explode('@', $jid)[0] ?? '';
        if ($user === '0') {
            return false; // PSA
        }
        if (preg_match('/^1313555\d{4}$|^131655500\d{2}$/', $user)) {
            return false; // bot by phone pattern
        }
        if (str_ends_with($jid, '@bot')) {
            return false; // MetaAI
        }
        return Jid::isPnUser($jid) || Jid::isLidUser($jid)
            || Jid::isHostedPnUser($jid) || Jid::isHostedLidUser($jid)
            || str_ends_with($jid, '@c.us');
    }

    private function getTcTokenEntry(string $storageJid): ?array
    {
        $res = $this->authState->keys->get('tctoken', [$storageJid]);
        $entry = $res[$storageJid] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /**
     * Build a <tctoken> node for the peer if we hold a valid token.
     * (buildTcTokenFromJid — returns the node instead of a content array.)
     */
    private function buildTcTokenNode(string $jid): ?BinaryNode
    {
        try {
            $storageJid = $this->resolveTcTokenJid($jid);
            $entry = $this->getTcTokenEntry($storageJid);
            $token = $entry['token'] ?? null;
            $timestamp = $entry['timestamp'] ?? null;
            if (!is_string($token) || $token === '' || $timestamp === null || self::isTcTokenExpired(is_numeric($timestamp) ? (int)$timestamp : null)) {
                if ($token !== null) {
                    $cleared = isset($entry['senderTimestamp'])
                        ? ['token' => '', 'senderTimestamp' => $entry['senderTimestamp']]
                        : null;
                    $this->authState->keys->set(['tctoken' => [$storageJid => $cleared]]);
                }
                return null;
            }
            return new BinaryNode('tctoken', ['t' => (string)$timestamp], new Bytes($token));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Store tokens from an iq/notification node containing <tokens><token type="trusted_contact">. */
    private function storeTcTokensFromNode(BinaryNode $result, string $fallbackJid): void
    {
        $tokensNode = GenericUtils::getBinaryNodeChild($result, 'tokens');
        if ($tokensNode === null) {
            return;
        }
        foreach (GenericUtils::getBinaryNodeChildren($tokensNode, 'token') as $tokenNode) {
            if (($tokenNode->attrs['type'] ?? '') !== 'trusted_contact') {
                continue;
            }
            $content = $tokenNode->contentString();
            if ($content === null) {
                continue;
            }
            $rawJid = Jid::normalizedUser($fallbackJid ?: ($tokenNode->attrs['jid'] ?? ''));
            if (!self::isRegularUserJid($rawJid)) {
                continue;
            }
            $storageJid = $this->resolveTcTokenJid($rawJid);
            $existing = $this->getTcTokenEntry($storageJid);
            $existingTs = (int)($existing['timestamp'] ?? 0);
            $incomingTs = (int)($tokenNode->attrs['t'] ?? 0);
            if (!$incomingTs || ($existingTs > 0 && $existingTs > $incomingTs)) {
                continue;
            }
            $entry = array_merge($existing ?? [], [
                'token' => $content,
                'timestamp' => (string)$incomingTs,
            ]);
            $this->authState->keys->set(['tctoken' => [$storageJid => $entry]]);
        }
    }

    private function handlePrivacyTokenNotification(BinaryNode $node): void
    {
        $tokensNode = GenericUtils::getBinaryNodeChild($node, 'tokens');
        if ($tokensNode === null) {
            return;
        }
        $from = Jid::normalizedUser($node->attrs['from'] ?? '');
        $senderLid = null;
        if (!empty($node->attrs['sender_lid']) && Jid::isLidUser(Jid::normalizedUser($node->attrs['sender_lid']))) {
            $senderLid = Jid::normalizedUser($node->attrs['sender_lid']);
        }
        $fallbackJid = $senderLid ?? $this->resolveTcTokenJid($from);
        $this->storeTcTokensFromNode($node, $fallbackJid);
    }

    /** issuePrivacyTokens(jids, timestamp?) */
    public function issuePrivacyTokens(array $jids, ?int $timestamp = null): BinaryNode
    {
        $t = (string)($timestamp ?? time());
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'privacy',
        ], [
            new BinaryNode('tokens', [], array_map(
                fn($jid) => new BinaryNode('token', ['jid' => Jid::normalizedUser($jid), 't' => $t, 'type' => 'trusted_contact']),
                $jids
            )),
        ]));
        return $result;
    }

    /**
     * Ensure we have issued our own trusted-contact token to this peer in the
     * current bucket (mirrors the recv/send paths in Baileys).
     */
    private function ensurePrivacyTokenIssued(string $peerJid): void
    {
        try {
            if (!self::isRegularUserJid($peerJid)) {
                return;
            }
            $storageJid = $this->resolveTcTokenJid($peerJid);
            $entry = $this->getTcTokenEntry($storageJid) ?? [];
            $senderTs = isset($entry['senderTimestamp']) ? (int)$entry['senderTimestamp'] : null;
            if (!self::shouldSendNewTcToken($senderTs)) {
                return;
            }
            $now = time();
            $issueJid = $this->resolveIssuanceJid($peerJid);
            $result = $this->issuePrivacyTokens([$issueJid], $now);
            // server returns our token for them in the result — store senderTimestamp
            $entry['senderTimestamp'] = $now;
            $this->authState->keys->set(['tctoken' => [$storageJid => $entry]]);
            $this->storeTcTokensFromNode($result, $peerJid);
        } catch (\Throwable $e) {
            $this->logger->debug(['err' => $e->getMessage()], 'privacy token issuance failed');
        }
    }
}
