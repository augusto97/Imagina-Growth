<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Models\ChannelAccount;

/**
 * Registros DNS que pide el asistente de dominio (M07, cumplimiento.md §8) y su
 * verificación en vivo: 3 CNAME de DKIM de SES, SPF recomendado y DMARC.
 */
final class EmailDomain
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @return list<array{type: string, name: string, value: string, purpose: string, required: bool, ok: bool|null}>
     */
    public function records(ChannelAccount $account, bool $check = false): array
    {
        $domain = (string) $account->credential('domain');
        if ($domain === '') {
            return [];
        }
        $records = [];
        foreach ((array) ($account->config['dkim_tokens'] ?? []) as $token) {
            $name = $token.'._domainkey.'.$domain;
            $value = $token.'.dkim.amazonses.com';
            $records[] = ['type' => 'CNAME', 'name' => $name, 'value' => $value, 'purpose' => 'dkim', 'required' => true,
                'ok' => $check ? in_array(strtolower($value), $this->dns->cname($name), true) : null];
        }
        $spf = 'v=spf1 include:amazonses.com ~all';
        $records[] = ['type' => 'TXT', 'name' => $domain, 'value' => $spf, 'purpose' => 'spf', 'required' => false,
            'ok' => $check ? $this->anyTxt($domain, fn (string $t) => str_starts_with(strtolower($t), 'v=spf1') && str_contains($t, 'amazonses.com')) : null];
        $records[] = ['type' => 'TXT', 'name' => '_dmarc.'.$domain, 'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domain, 'purpose' => 'dmarc', 'required' => true,
            'ok' => $check ? $this->anyTxt('_dmarc.'.$domain, fn (string $t) => str_starts_with(strtoupper(trim($t)), 'V=DMARC1')) : null];

        return $records;
    }

    /** @param callable(string): bool $match */
    private function anyTxt(string $name, callable $match): bool
    {
        foreach ($this->dns->txt($name) as $txt) {
            if ($match($txt)) {
                return true;
            }
        }

        return false;
    }
}
