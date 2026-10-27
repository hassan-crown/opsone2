# Security and safety

- Do not print, commit, or copy `.env`, credentials, encryption keys or secrets into AI output.
- Do not regenerate an existing APP_KEY (would invalidate encrypted data).
- Reject destructive commands unless explicitly approved for the named environment and target.
- Principle of least privilege for users, services and credentials; never chmod 777.
- Verify authentication/authorization server-side; never treat hidden UI as authorization.
- Escape output and parameterize queries; protect CSRF according to the selected frontend mode.
- Validate file uploads and authorization of private attachments; serve Laravel only via public/.
- Redact personal data and payment details in logs, PRs and diagnostics.
- Preserve production backups and rollback steps before migrations or releases.
- Third-party integrations need documented credential sourcing and webhook signature checks.
