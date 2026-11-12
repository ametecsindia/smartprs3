# On-prem licensing — SmartPRS3 vs SmartPRS2 (checked 12-11-2026)

Compared: SmartPRS2 `LicenseService`, `LicenseSigner`, `LicenseFile`, `UpdateServerController`, `OnpremClientController`,
`ClientUpdateController`, `LicenseGate` against SmartPRS3 `surface/LicenceAuthority.php`, `surface/Licence.php`,
`public/update.php`, `public/activate.php`, `surface/actions/onprem.php`, `surface/partials/boot-hooks.php`.

## Tested end to end — 35 / 35 pass

A licence server (LicenceAuthority behind the same `/update/*` contract) and two on-prem installs with different
machine fingerprints, talking over HTTP:

- Key format `SPRS-XXXX-XXXX-XXXX-XXXX`, stored as sha256 + encrypted copy the Super Admin can re-show; issued = pending.
- Online activation binds the first machine; events activated → machine_bound → verified; certificate HMAC-signed with the key.
- Same key on a second machine: refused ("already activated on another machine"), one `rejected` event a day, `last_mismatch_at` set.
- Renewal on the server reaches the install at its daily check-in.
- Expiry: renew mode blocks the app.
- Updates check: not granted / granted update offered / up to date; AMC expired refuses updates.
- Second server seat refused (`device_limit_reached`, limit 1).
- Shift machine: the new server activates; the old one is blocked at its next check-in (`server_mismatch`).
- Revoke: install blocked, key remembered as revoked, activation and updates refused.
- Unknown key → neutral 422 message; short key refused locally.
- `.lic`: activates offline on its own machine; refused on another (wrong_machine), without a fingerprint (not_locked),
  tampered (invalid signature), expired, or when active employees exceed its seats; deleting license.lic ends it.
- Offline `.lic` disabled → the key inside it activates online.
- Super Admin desk: key only when fully paid (price + GST) or "activate on partial" ticked; one live licence per client;
  renew by date / months / term; shift; revoke; delete — same rules as SmartPRS2.

## Differences from SmartPRS2

1. **Notify-mode expiry** — SmartPRS2 blocks an expired licence in both modes (notify only changes the message).
   SmartPRS3 lets a notify-mode install keep running after expiry (config/plans.json says so).
2. **No alert email to sales** when a different machine uses a key (SmartPRS2 `alertSalesMismatch`).
3. **No emails to the client** with the key, the .lic, or the invoice + pay link.
4. **No public pay page** (`/licence/{token}`, Razorpay) and no automatic key on full online payment.
5. **No in-app updates** — SmartPRS3's check offers an update but gives no download link; updates are applied by IT
   (deploy/DEPLOY.md). The FAQ and Licence Agreement copied from SmartPRS2 still promise an in-app Updates screen with
   checksum, backup and rollback.
6. Re-issuing a .lic falls back to the live licence's bound fingerprint, not (as SmartPRS2 also does) the last active
   server seat's fingerprint.
7. Single edition ("full") — SmartPRS2's L1/L2/L3 edition check is gone by design; SPRSX1 offline codes were already
   retired in SmartPRS2.
