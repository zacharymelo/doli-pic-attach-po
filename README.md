# RFQ Product Images (rfqimages) for Dolibarr

Sends product images along with vendor price requests (supplier proposals).

## How it works
1. On a product, open the **Price request images** tab and turn on **Send images with price requests**. Untick any files that should not go out. New uploads are included by default.
2. On a price request, click **Send by email**. The images of every flagged product on its lines are attached, named `<product ref>_<file>`.
3. If the total size is above the limit in setup (default 10 MB), nothing is attached. Instead, a public share link is created for each of those files only and put in the message:
   - where the template contains `__RFQIMAGES_LINKS__`, or
   - appended at the end when sending (setting "Append links automatically").
4. Public links can be revoked per file from the product tab.

Other product files remain private.

## Settings
Home → Setup → Modules → RFQ Product Images:
- File extensions (default `jpg,jpeg,png,gif,webp`)
- Maximum total attachment size (MB, 0 = always attach)
- Append links automatically
- Debug mode (`/custom/rfqimages/ajax/debug.php?mode=overview|product|proposal&id=N`)

## Development
```bash
docker compose -p rfqimages up -d   # http://localhost:8104 (admin/admin), mail UI http://localhost:8025
./build.sh                          # rfqimages-<version>.zip for Deploy external module
```
