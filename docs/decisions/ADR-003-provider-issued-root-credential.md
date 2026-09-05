# ADR-003 — Where a provider-issued one-time root credential lives

**Status:** Accepted. Implemented as the CBM-010 correction.

## Context

`CloudProviderInterface::createServer()` used to return `ProviderServerData`.
That type deliberately carries no credential, and that is correct: it is also the
shape returned by `getServer()`, `listServers()` and
`findByProvisioningToken()`, and those are read, compared, logged and reconciled
constantly. A password in that shape would be a password in all of them.

But it was also the only thing `createServer()` could return, so a provider whose
create response contains the only copy of a generated root password had no
normalized channel to hand it over, and `ServerPersister` had nothing to write
into `servers.root_password_encrypted`. `FakeProvider` never issued one, so every
success test passed with a null column, and the root-password tests populated
that column by hand — bypassing the provider contract entirely.

A create-specific result type closes the ordinary path and nothing more. The
credential then lives in one stack frame, and Phase 7 exists because that frame
is not safe: a create can succeed, the remote machine can exist, and the local
`servers` + `subscriptions` transaction can still fail. Reconciliation later
finds the machine by its durable provisioning token, and the credential-free DTO
it gets back is — correctly — no help at all.

## Decision

Two parts, and the boundary between them is the whole decision.

**Release 1.0 pulls forward `SupportsPasswordReset` as an internal
provisioning-recovery capability only.** When a create-time credential is lost
before delivery, recovery asks the provider to issue a new root password for the
machine that already exists, and stores that instead. The lost password is not
recovered, guessed, escrowed or reconstructed.

**Customer-facing reset-password automation remains Release 1.1.** There is no
button, no rotation flow, no customer-reachable path. The Telegram boundary
tests prove it.

The distinction that makes rotation safe here and nowhere else is that *the
customer has not been given this server yet*. No password has been shown to
anybody, so invalidating the old one locks nobody out. That is a fundamentally
different safety class from repeating a create, a reboot or a delete: rotating
an undisclosed pre-delivery credential duplicates no VPS and destroys no data.
The moment a server has been delivered, this stops being true, which is exactly
why the capability is internal.

## Consequences

- **No second secret store.** Nothing durable holds a plaintext credential
  except `servers.root_password_encrypted`, through the model's encrypted cast.
  That includes the simulator: `FakeProvider` keeps a one-way SHA-256 verifier
  in `fake_provider_servers.root_password_verifier` and answers only
  `credentialMatches()`. It cannot produce a password, which is both the
  security property and a more honest model of a provider — a real reset makes
  the previous credential stop working, and a verifier shows that where a
  plaintext column could only pretend to. Being a test provider is not an
  exemption: the table is created by the same `migrate` that builds production.
- **No plaintext escrow.** A credential is never parked to be picked up later,
  not even between a create that returned a still-building machine and the
  moment it becomes active. It is dropped, and recovery rotates.
- **A create-specific transient result.** `createServer()` returns
  `ProviderCreateResult { ProviderServerData $server; ?SensitiveRootCredential
  $rootCredential; }`. Every other provider read stays credential-free.
- **A value object that cannot leak by accident, and fails closed on storage.**
  `SensitiveRootCredential` holds the plaintext privately, is not `Stringable`,
  and redacts through `jsonSerialize` and `__debugInfo` — those happen in logs
  and debuggers, where throwing would turn a diagnostic into an outage.
  `__serialize` instead **throws**: serializing means somebody put a credential
  into a queue payload, a session or a cache entry, and a quiet placeholder
  would hide that architectural mistake and leave a credential-shaped hole
  downstream. One method returns plaintext, named `reveal()` so a call site is
  obvious in review.
- **Successful local persistence writes the credential and nothing else does.**
  It is passed to `ServerPersister` explicitly, written inside the same
  transaction as the server, and excluded from the audit entry and the success
  outbox payload — those record only *whether* a credential was stored.
- **A lost pre-delivery credential is rotated, not recovered.** A worker that
  dies mid-rotation loses that password too; the next recovery rotates again and
  the newer password supersedes any earlier one nobody ever received.
- **Recovery has its own durable budget.** Counted as
  `ProvisioningStage::CredentialRecovery` attempts, default maximum 3
  (`cloudbot.provisioning.credential_recovery_max_attempts`). It never touches
  `orders.attempts`, which remains strictly the provider *create* budget: a run
  of failed password resets must not retire an order whose machine is running.
- **A provider without safe reset capability parks recovery.** The order is held
  in `needs_attention` with the reason `credential_recovery_unsupported`.
- **An exhausted budget parks recovery** with `credential_recovery_exhausted`.
- **An existing remote VPS means no automatic refund, ever.** The customer's
  money bought a machine that is running and billable. It also means no second
  create and no token regeneration.
- **An inaccessible server is never marked delivered.** An order marked
  provisioned is an order the system says the customer has; saying that about a
  machine nobody can log into is worse than admitting the problem.
- **Phase 10.** The Hetzner adapter must implement `SupportsPasswordReset` via
  the provider's reset-password endpoint — which returns a new root password
  plus an action — before it is considered conforming for password-based
  recovery. That HTTP mapping is Phase 10 work and is not in this correction.

## Conservative crash-recovery rule

When a remote VPS exists but the local atomic persistence never committed, core
code has **deliberately** lost any create-time transient credential evidence.
There is no record that a credential was issued, and none is kept — that is the
design, not a gap.

Release 1.0 recovery therefore reasons only from what it can establish:

- If safe provider-level password reset is available and a known credential is
  required for delivery, **rotate before final delivery**. The customer has not
  been given this server, so invalidating an unknown previous password costs
  nobody anything.
- If core cannot safely establish customer access, **do not mark the order
  Provisioned**. An order marked provisioned is an order the system says the
  customer has; saying that about a machine nobody can log into is worse than
  admitting the problem.
- **Park in `needs_attention` rather than guessing**, with
  `credential_recovery_unsupported` or `credential_recovery_exhausted`.

Two inferences are specifically forbidden. Recovery must **not** conclude "a
credential was definitely issued" from a generic remote `ProviderServerData` —
that DTO says nothing about credentials by construction, and reading an
expectation into its absence would be inventing evidence. And no secret
expectation may be written into provider metadata to carry that knowledge
forward; metadata is a whitelisted, widely-read surface and putting a
credential-shaped hint there is how a credential eventually follows it.

## Rejected alternatives

- **Persist the credential before the server row exists.** Needs a second
  durable secret location. §37/§80 name exactly one.
- **Create a partial `servers` row first.** Breaks the one Order → one Server →
  one Subscription transaction and the delivery invariants that depend on it.
- **Re-read the credential during recovery.** Impossible by definition: it is
  one-time, and `getServer()` must stay credential-free.
- **Deliver it to the customer at create time.** Forbidden: a root password may
  never enter an outbox payload, a notification or Telegram state.
- **Accept the window with an operator remediation.** Workable, and strictly
  worse: it leaves paid customers waiting on a human for a problem the provider
  can solve in one call.
- **Let the simulator keep the plaintext it issued.** Rejected for the reason
  above: `fake_provider_servers` is an application table, and "it is only a test
  provider" is not an exemption from a rule about plaintext credential columns.
  Encrypting that column would be no better — reversible storage is what escrow
  means.

## Invariants this preserves

- The root password is stored only in `servers.root_password_encrypted`, and
  `servers.root_password_encrypted` stays **nullable**. A create that carries no
  credential is a valid normalized provider result: a provider authenticating by
  key issues no password, and the contract says so with null rather than an
  empty string somebody has to interpret. Nothing here claims every VPS requires
  a root password.
- It never appears in `provider_metadata`, `SafeMetadata`, provisioning attempt
  summaries, outbox payloads, notification logs, audit records, Telegram
  updates, Telegram state, queue payloads, logs or exception context.
- Ordinary provider reads stay credential-free.
- Revealing it stays owner-only and audited, unchanged from Phase 9.
- One Order → one Server → one Subscription is not weakened to make room.
- The provider create budget, the token idempotency and the no-refund-on-
  uncertainty rules are untouched.
