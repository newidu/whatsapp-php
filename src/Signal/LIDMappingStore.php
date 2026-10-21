<?php
declare(strict_types=1);

namespace BaileysPHP\Signal;

use BaileysPHP\Auth\SignalKeyStore;
use BaileysPHP\Utils\Logger;
use BaileysPHP\WABinary\Jid;

/**
 * Simplified port of Baileys LIDMappingStore (no inflight coalescing needed in sync PHP).
 * Storage layout matches Baileys: 'lid-mapping' { pnUser: lidUser, "{lidUser}_reverse": pnUser }
 */
final class LIDMappingStore
{
    /** @var array<string,string> */
    private array $cache = [];
    /** @var null|callable(list<string>):?list<array{lid:string,pn:string}> */
    private $pnToLIDFunc;

    public function __construct(
        private SignalKeyStore $keys,
        private ?Logger $logger = null,
        ?callable $pnToLIDFunc = null,
    ) {
        $this->pnToLIDFunc = $pnToLIDFunc;
    }

    public function setPnToLIDFunc(callable $fn): void
    {
        $this->pnToLIDFunc = $fn;
    }

    /** @param list<array{lid:string,pn:string}> $pairs */
    public function storeLIDPNMappings(array $pairs): void
    {
        $batch = [];
        foreach ($pairs as $pair) {
            $lid = $pair['lid'] ?? '';
            $pn = $pair['pn'] ?? '';
            // tolerate swapped args like upstream validation
            if (Jid::isPnUser($lid) && Jid::isLidUser($pn)) {
                [$lid, $pn] = [$pn, $lid];
            }
            if (!Jid::isLidUser($lid) || !Jid::isPnUser($pn)) {
                continue;
            }
            $lidUser = Jid::decode($lid)['user'] ?? '';
            $pnUser = Jid::decode($pn)['user'] ?? '';
            if ($lidUser === '' || $pnUser === '') {
                continue;
            }
            $batch[$pnUser] = $lidUser;
            $batch[$lidUser . '_reverse'] = $pnUser;
            $this->cache['pn:' . $pnUser] = $lidUser;
            $this->cache['lid:' . $lidUser] = $pnUser;
        }
        if ($batch !== []) {
            $this->keys->set(['lid-mapping' => $batch]);
        }
    }

    public function getLIDForPN(string $pn): ?string
    {
        $r = $this->getLIDsForPNs([$pn]);
        return $r[0]['lid'] ?? null;
    }

    /**
     * @param list<string> $pns
     * @return list<array{lid:string,pn:string}>
     */
    public function getLIDsForPNs(array $pns): array
    {
        $out = [];
        $missing = [];
        foreach ($pns as $pn) {
            if (!Jid::isPnUser($pn) && !Jid::isHostedPnUser($pn)) {
                continue;
            }
            $decoded = Jid::decode($pn);
            $pnUser = $decoded['user'];
            $lidUser = $this->cache['pn:' . $pnUser] ?? null;
            if ($lidUser === null) {
                $stored = $this->keys->get('lid-mapping', [$pnUser]);
                $lidUser = $stored[$pnUser] ?? null;
                if (is_string($lidUser)) {
                    $this->cache['pn:' . $pnUser] = $lidUser;
                }
            }
            if (is_string($lidUser) && $lidUser !== '') {
                $dev = $decoded['device'] ?? 0;
                $server = ($decoded['server'] ?? '') === 'hosted' ? 'hosted.lid' : 'lid';
                $out[] = ['lid' => Jid::encode($lidUser, $server, $dev ? (int)$dev : null), 'pn' => $pn];
            } else {
                $missing[] = $pn;
            }
        }
        if ($missing !== [] && $this->pnToLIDFunc !== null) {
            try {
                $fetched = ($this->pnToLIDFunc)($missing);
                if ($fetched) {
                    $this->storeLIDPNMappings($fetched);
                    foreach ($fetched as $pair) {
                        // only add pairs for requested pns (by user)
                        foreach ($missing as $pn) {
                            if ((Jid::decode($pn)['user'] ?? '') === (Jid::decode($pair['pn'])['user'] ?? '')) {
                                $decoded = Jid::decode($pn);
                                $lidUser = Jid::decode($pair['lid'])['user'] ?? '';
                                $dev = $decoded['device'] ?? 0;
                                $out[] = ['lid' => Jid::encode($lidUser, 'lid', $dev ? (int)$dev : null), 'pn' => $pn];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warn(['err' => $e->getMessage()], 'LID fetch failed');
            }
        }
        return $out;
    }

    public function getPNForLID(string $lid): ?string
    {
        $decoded = Jid::decode($lid);
        if (!$decoded) {
            return null;
        }
        $lidUser = $decoded['user'];
        $pnUser = $this->cache['lid:' . $lidUser] ?? null;
        if ($pnUser === null) {
            $stored = $this->keys->get('lid-mapping', [$lidUser . '_reverse']);
            $pnUser = $stored[$lidUser . '_reverse'] ?? null;
            if (is_string($pnUser)) {
                $this->cache['lid:' . $lidUser] = $pnUser;
            }
        }
        if (!is_string($pnUser) || $pnUser === '') {
            return null;
        }
        $dev = $decoded['device'] ?? 0;
        return Jid::encode($pnUser, 's.whatsapp.net', $dev ? (int)$dev : null);
    }
}
