# ComplianceIQ

Enterprise compliance knowledge base built on Drupal 11, demonstrating [Scolta](https://scolta.io) semantic search across regulatory content.

## Quick Start

```bash
git clone <repo>
cd legal-compliance
ddev start
```

The DDEV post-start hook runs `composer install` and imports the database automatically. Visit https://complianceiq.ddev.site.

Admin login: `admin` / `admin`

## What's Inside

**~140+ pages** across 6 content types:
- **59 regulation sections** — GDPR (22), HIPAA (10), CCPA/CPRA (7), SOX (5), FERPA (3), ADA/Section 508 (4), FedRAMP (2), PCI-DSS (6)
- **30 guidance articles** — Plain-language explanations for Legal, IT, and Executive audiences
- **8 enforcement cases** — ICO, HHS OCR, and FTC enforcement actions
- **15 checklists** — Role-based and regulation-based compliance checklists
- **10 comparisons** — Cross-regulation comparison pages
- **20 glossary terms** — Compliance terminology with regulation-specific definitions

## Showcase Queries

Try these at https://complianceiq.ddev.site:

| Query | Demonstrates |
|---|---|
| "we got hacked, what do we do" | Cross-regulation breach response: GDPR Art. 33, HIPAA BNR, CCPA §1798.150 |
| "can we email customers in Europe" | GDPR consent, ePrivacy, data transfers |
| "someone asked us to delete their data" | GDPR Art. 17, CCPA §1798.105, comparison page |
| "student data in the cloud" | FERPA school official exception, FedRAMP |
| "audit is next month" | SOX 404, HIPAA risk assessment, PCI-DSS SAQ |
| "board wants to know our cyber risk" | SOX 302, SEC disclosure rules |
| "credit card data on our servers" | PCI-DSS Req. 3, tokenization, breach cases |

## Content Refresh

To regenerate content (requires `ANTHROPIC_API_KEY`):

```bash
ddev drush complianceiq:import-regulations
ddev drush complianceiq:import-cases
ddev drush complianceiq:generate-guidance
ddev drush complianceiq:generate-checklists
ddev drush complianceiq:generate-comparisons
ddev drush complianceiq:generate-glossary
ddev drush complianceiq:cross-reference
ddev export-db --gzip --file=db/dump.sql.gz
```

## Structure

```
├── .ddev/                          DDEV configuration
├── config/sync/                    Drupal configuration export
├── db/dump.sql.gz                  Database dump (committed)
├── web/
│   ├── modules/custom/
│   │   ├── complianceiq_import/    Import + AI generation Drush commands
│   │   └── complianceiq_scolta/   Search controller + Scolta integration
│   └── themes/custom/complianceiq/ Corporate theme (Twig + CSS)
├── SOURCES.md                      Regulatory source documentation
└── README.md
```

## Not Legal Advice

For demonstration purposes only. Content sourced from public domain government publications. See SOURCES.md for full attribution.
