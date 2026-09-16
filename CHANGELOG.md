# Changelog

## 1.0.0 — 2026-09-16
- Product extrafield "Send images with price requests" and a "Price request images" product tab to pick files and revoke public links.
- Price request emails: images of flagged products are attached automatically (copied to the mail temp folder, prefixed with the product ref).
- Over the size limit, public share links (`document.php?hashp=`) are created for those files only and added to the message, via `__RFQIMAGES_LINKS__` or appended at send.
- Setup: file extensions, size limit, auto-append toggle, debug endpoint.
