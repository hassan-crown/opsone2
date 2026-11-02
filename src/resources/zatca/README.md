# ZATCA resources (ADR-0033)

`xsd/` holds the OASIS **UBL 2.1** schemas (OS release, 4 Nov 2013, `http://docs.oasis-open.org/ubl/os-UBL-2.1/xsd/`):
`maindoc/UBL-Invoice-2.1.xsd` and the `common/` modules it imports (CCTS, CBC/CAC/EXT, data types, signature
components, xmldsig and XAdES). ZATCA (Fatoora) invoices, credit notes and debit notes all use the `Invoice` root, so
the CreditNote/DebitNote maindocs are not bundled. The files are unmodified and keep their OASIS copyright notice;
OASIS publishes them for implementation under its IPR policy, which allows copying and redistribution with the notice.

ZATCA's own Schematron business rules (BR-KSA-*) are not bundled: they ship inside ZATCA's SDK under ZATCA's terms.
They are checked by ZATCA's compliance endpoint during onboarding (`docs/zatca-onboarding.md`).
