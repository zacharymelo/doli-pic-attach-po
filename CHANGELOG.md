# Changelog

## 1.1.0 — 2026-09-16
- Purchase orders: product files are now also attached (or linked) when emailing a purchase order. Setup has on/off switches for price requests and purchase orders (both on).
- Product tab renamed "Vendor email images" and now warns about files that are not sent because their extension is not in the setup list (PDFs are not included by default; add `pdf` in setup).
- Workaround for a Dolibarr core bug: Cancel on a price request's line form redirected to `/supplier_proposal/3D<id>` (a 404). The return URL is now repaired.
- No longer requires the Price requests module; only Products.
- **Upgrade:** after deploying, disable and re-enable the module so the new purchase order hook is registered.

## 1.0.0 — 2026-09-16
- Product extrafield "Send images with price requests" and a "Price request images" product tab to pick files and revoke public links.
- Price request emails: images of flagged products are attached automatically (copied to the mail temp folder, prefixed with the product ref).
- Over the size limit, public share links (`document.php?hashp=`) are created for those files only and added to the message, via `__RFQIMAGES_LINKS__` or appended at send.
- Setup: file extensions, size limit, auto-append toggle, debug endpoint.
