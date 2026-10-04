# Release Notes for Carrier for GLS

## 5.0.0 - 2026-10-03

- Initial release.
- GLS through MyGLS for Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia and Serbia, in production and on the MyGLS test system.
- Labels in one call (`PrintLabels`) as PDF A4 (2 × 2 or 4 × 1), thermal PDF or ZPL (203/300 dpi), with multi-parcel shipments.
- Reprints and bulk A4 sheets through `GetPrintedLabels`, and voids through `DeleteLabels`.
- Cash on delivery, and parcel-shop and locker delivery (`PSD`).
- Saturday delivery and FlexDelivery email/SMS notices.
- Batch tracking (`GetParcelListStatuses`, 100 parcels per request) with full scan history, falling back to `GetParcelStatuses` on hosts without the batch method.
- Daily sync of every GLS parcel shop and locker from GLS's public point map.
- MyGLS error lists returned on HTTP 200 are read per method: a bad login is reported as such, and the duplicate-request guard (error 31) is treated as "may have been created", never retried.
