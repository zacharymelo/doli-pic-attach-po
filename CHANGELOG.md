# Changelog

## 1.5.1 — 2026-09-17
- Fix: applying an email template from the template dropdown in the email form removed `__RFQIMAGES_LINKS__` from the message and dropped the product file attachments. The dropdown reloads the form with `action=send`, which was mistaken for sending. The key now stays until the email is really sent, and the product files are attached again after a template is applied (core clears attachments at that point).

## 1.5.0 — 2026-09-17
- The product settings moved from the separate "Vendor email images" tab into a **Vendor email images** section at the bottom of the product's **Documents** tab, below the linked files. Same controls: send switch, Include checkboxes, share link status and revoke.
- The old tab and its page (`product_images.php`) are removed.
- **Upgrade:** disable and re-enable the module after deploying (registers the Documents tab hook and removes the old tab).

## 1.4.0 — 2026-09-16
- **File types:** chosen from a list of common image, document, CAD and archive types, plus a box for any other extension (replaces the comma-separated text field).
- **Send for product categories:** products in selected categories (including subcategories) are sent without switching each product on. The product tab shows when a product is sent because of its category.
- **Include new files:** choose whether files never ticked or unticked on a product's tab are sent (default) or left out until ticked.
- **Resize attached images:** attach jpg/png/gif shrunk to a maximum size in pixels; the size limit is checked on the resized copies. Share links still point to the originals.
- **Remove share links after N days:** a daily scheduled job removes share links this module created once they haven't been emailed for N days. Links shared by hand, or replaced since, are never touched. Revoking a link on the product tab also stops tracking it.
- **Share link heading:** set the text shown before the links, or hide it.
- Setup page grouped into sections.
- **Upgrade:** disable and re-enable the module after deploying (creates the new share tracking table and the scheduled job). Link removal needs the Scheduled jobs module enabled.

## 1.3.0 — 2026-09-16
- `__RFQIMAGES_LINKS__` is back and now always works: wherever it appears in a price request or purchase order email, it is replaced with share links when the email is sent. Files are still attached as usual, so a template can include both.
- The key is listed in the email template editor. In an open email form it stays visible as `__RFQIMAGES_LINKS__` until you send; share links are only created at send.
- Files too large to attach: links go where the key is, or at the end of the message if there is no key. Their share links are now also created at send instead of when the form opens, so opening an email form never makes files public.
- **Upgrade:** disable and re-enable the module after deploying.

## 1.2.0 — 2026-09-16
- Removed the `__RFQIMAGES_LINKS__` email template key. It was empty whenever files were attached (the normal case), so it looked like it did nothing. When files are too large, share links are always added at the end of the message. (Reinstated in 1.3.0.)
- Removed the "Append links automatically" setting: with the key gone, turning it off would have made files public without sending the links. The old setting is deleted on re-enable.
- **Upgrade:** disable and re-enable the module after deploying. If you added `__RFQIMAGES_LINKS__` to an email template, remove it from the template.

## 1.1.1 — 2026-09-16
- Removed the workaround for the core price request Cancel redirect (`/supplier_proposal/3D<id>`). It is unrelated to this module and is being fixed in Dolibarr core instead.

## 1.1.0 — 2026-09-16
- Purchase orders: product files are now also attached (or linked) when emailing a purchase order. Setup has on/off switches for price requests and purchase orders (both on).
- Product tab renamed "Vendor email images" and now warns about files that are not sent because their extension is not in the setup list (PDFs are not included by default; add `pdf` in setup).
- Workaround for a Dolibarr core bug: Cancel on a price request's line form redirected to `/supplier_proposal/3D<id>` (a 404). (Removed in 1.1.1.)
- No longer requires the Price requests module; only Products.
- **Upgrade:** after deploying, disable and re-enable the module so the new purchase order hook is registered.

## 1.0.0 — 2026-09-16
- Product extrafield "Send images with price requests" and a "Price request images" product tab to pick files and revoke public links.
- Price request emails: images of flagged products are attached automatically (copied to the mail temp folder, prefixed with the product ref).
- Over the size limit, public share links (`document.php?hashp=`) are created for those files only and added to the message, via `__RFQIMAGES_LINKS__` or appended at send.
- Setup: file extensions, size limit, auto-append toggle, debug endpoint.
