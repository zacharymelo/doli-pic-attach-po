# Vendor Product Images (rfqimages) for Dolibarr

Sends product images (and other chosen files) along with vendor **price requests** and **purchase orders**.

## How it works
1. On a product, open the **Vendor email images** tab and turn on **Send images to vendors**. Untick any files that should not go out. New uploads are included by default.
2. On a price request or purchase order, click **Send by email**. The files of every flagged product on its lines are attached, named `<product ref>_<file>`.
3. If the total size is above the limit in setup (default 10 MB), nothing is attached. Instead, a public share link is created for each of those files only and added at the end of the message when it is sent.
4. **Links as well as attachments:** put `__RFQIMAGES_LINKS__` in an email template (or type it in the message). When the email is sent, it is replaced with share links to the same files, and the files are still attached. Links are only created at send, so just opening the form never makes files public.
5. Public links can be revoked per file from the product tab.

Other product files remain private.

**PDFs:** only `jpg,jpeg,png,gif,webp` are sent by default. Add `pdf` to the extensions in setup to send PDF drawings or spec sheets. The product tab warns about files that are skipped because of their extension.

## Settings
Home → Setup → Modules → Vendor Product Images:
- Price requests / Purchase orders on/off
- File extensions (default `jpg,jpeg,png,gif,webp`)
- Maximum total attachment size (MB, 0 = always attach)
- Debug mode (`/custom/rfqimages/ajax/debug.php?mode=overview|product|proposal|order&id=N`)

## Upgrading
Deploy the new zip, then **disable and re-enable** the module so new hooks are registered. Settings, file picks and product flags are kept.

## Development
```bash
docker compose -p rfqimages up -d   # http://localhost:8104 (admin/admin), mail UI http://localhost:8025
./build.sh                          # rfqimages-<version>.zip for Deploy external module
```
