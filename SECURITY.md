# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report vulnerabilities privately to PN Scripts through the contact form at
https://pnscripts.com, with "PN Invoice security" in the subject. Include the
affected version, a description, and a minimal reproduction (for example a
sample XML file with all personal and company data removed).

You will receive an acknowledgement within 5 working days. We aim to publish a
fix within 30 days for confirmed issues and will credit you in the changelog
unless you prefer otherwise.

## Scope and hardening notes

- XML input is parsed with network access disabled (`LIBXML_NONET`); documents
  with a DOCTYPE declaration are rejected, which blocks XML external entity (XXE)
  and entity expansion attacks.
- XSD validation only loads the schema files bundled in `resources/schemas/`.
- `CommandSchematronValidator` runs the command you configure without a shell
  (argument array). Never build that command from untrusted input.
- Invoices contain personal and business data. This library does not log,
  store or transmit any document content.
