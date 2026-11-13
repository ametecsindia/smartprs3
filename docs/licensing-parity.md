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

## Differences from SmartPRS2 (closed 13-11-2026)

Closed: expired blocks in both modes (notify only words it "LC Expired"); the key, the .lic and the invoice + pay link are
emailed to the client; the public pay page `/licence.php?t=…` takes the balance by Razorpay and a full payment issues and
emails the key; a different machine using a key emails Ametecs (`onprem.licence_alert_email`, else the platform mailbox);
a re-issued .lic falls back to the last-seen active server seat; in-app updates (Releases → Install / Check for update).

Still different by design: single edition ("full"); no PDF attached to the invoice email (the pay page shows the invoice);
the pay page is `/licence.php?t=…` rather than `/licence/{token}` (no nginx change needed).
