# Security Policy

## Reporting a vulnerability

Please report security issues privately — **do not** open a public GitHub issue
for a vulnerability.

- Email: **nishatbd3388@gmail.com** with the subject `WP-Ultra-MCP security`.
- Include the affected version, a description, and reproduction steps.

You will get an acknowledgement within a few days. Please allow a reasonable
window to ship a fix before any public disclosure.

## Supported versions

Fixes land on the latest release only. Update to the newest version before
reporting — the issue may already be resolved.

## Trust model — read this before deploying

WP-Ultra-MCP turns a WordPress site into an MCP server that exposes ~305
abilities to an AI client. Understand what that means:

- **An MCP caller is administrator-equivalent.** The abilities can run PHP and
  WP-CLI, execute SQL, read/write files, install and delete plugins/themes,
  edit options, manage users and roles, and export the database. Anyone who
  holds a valid MCP credential can do anything an administrator can do. Treat
  the application password like an admin password.

- **Access is off by default.** The MCP endpoint is disabled until an admin
  enables "AI control" on the Connect page, and it is scoped to
  admins-or-granted-roles (not any logged-in user).

- **Role grants are a convenience, not a sandbox.** `manage-access` can grant a
  non-admin role a limited set of abilities. This narrows what a *delegated*
  role may call; it does **not** make the delegated abilities safe. RCE-class
  and privilege-escalation categories (`code-execution`, `database`,
  `filesystem`, `system`, `users`) — and the specific abilities
  `execute-php`, `run-wp-cli`, `execute-wp-query`, file writes,
  `manage-plugin-theme`, `manage-user`, `roles-manage`, `multisite-manage`,
  `site-migrate`, `staging-clone`, `option-set` — can **never** be delegated to
  a non-admin, and this is enforced at both grant time and execution time.

- **Destructive actions are confirm-gated**, mutations are audit-logged, and
  reversible changes are snapshotted into the undo ring before they run — but
  none of that substitutes for controlling who holds a credential.

## Hardening recommendations

- Enable AI control only while you need it; disable it when idle.
- Generate a dedicated application password per client and revoke unused ones.
- Keep the DB-snapshot / backup directories out of the web root where the host
  allows it (the plugin also protects them with `.htaccess` + `web.config` and
  an unguessable directory suffix).
- Turn off any public REST endpoints you don't use
  (`wpultra_public_endpoints_disabled`).
- Run the site on HTTPS so application passwords aren't sent in the clear.

## What the plugin does defensively

- Application passwords are generated via core's `WP_Application_Passwords`,
  revealed once, and never persisted by the plugin.
- The filesystem path jail rejects traversal, symlinks, NTFS alternate data
  streams, control characters, and trailing-dot/space extension tricks, and
  confines executable file types to a hardened sandbox directory.
- SQL is classified with an allow-list; anything that can mutate or write to
  disk requires `confirm: true`.
- `option-get`/`option-set` deny-list secrets (salts, `*_key`, `*secret*`,
  `*password*`) so credentials cannot round-trip through the AI.
