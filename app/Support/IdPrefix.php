<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical registry of every prefixed-ULID prefix used in the data model.
 *
 * The list mirrors PLAN §5. Adding a new prefix is the only sanctioned way
 * to introduce a new prefixed-ULID resource — the Id and cast layers refuse
 * to mint or parse anything that isn't on this list, so a typo can't silently
 * create an alternate-universe resource type.
 */
final class IdPrefix
{
    /**
     * @var list<string>
     */
    public const PREFIXES = [
        // Tenancy spine
        'prj_', 'env_', 'apik_', 'kid_',

        // End-user identity
        'user_', 'idn_', 'eml_', 'phn_', 'ext_', 'entacc_', 'pkey_', 'totp_', 'bcc_',

        // Verification + flow state
        'ver_', 'client_', 'sess_', 'sact_', 'sia_', 'sui_', 'sit_', 'act_', 'chal_',

        // Organizations
        'org_', 'orgmem_', 'orginv_', 'orgdom_', 'orgreq_', 'role_', 'perm_',

        // Restrictions and platform settings
        'inv_', 'redir_', 'allow_', 'block_',
        'jtmpl_', 'oac_', 'oat_', 'ort_', 'oauthp_', 'entcon_',
        'scimt_', 'scimm_', 'wait_',

        // Webhooks + delivery
        'whe_', 'whd_', 'evt_',

        // Templates, domains, audit
        'tmpl_', 'dmn_', 'audit_',
    ];

    /**
     * Whether the supplied prefix is registered.
     *
     * Always pass the *full* prefix including the trailing underscore
     * (e.g. `'user_'`, never `'user'`).
     */
    public static function isRegistered(string $prefix): bool
    {
        return in_array($prefix, self::PREFIXES, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::PREFIXES;
    }
}
