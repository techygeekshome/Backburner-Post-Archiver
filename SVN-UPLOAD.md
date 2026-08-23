# Getting BackBurner Post Archiver live on WordPress.org

The plugin was approved on 21 Aug 2026. Nothing appears on
https://wordpress.org/plugins/backburner-post-archiver until the files are committed over SVN —
WordPress.org cannot do that for you.

Everything in this folder is ready to commit as-is.

## What is here

```
trunk/       backburner-post-archiver.php, readme.txt   (version 1.0.4, the approved build)
tags/1.0.4/  the same two files, tagged as the release
assets/      banner-1544x500.png, banner-772x250.png, screenshot-1.png
```

`assets/` also needs the two icons that already exist in the repo — `icon-128x128.png` and
`icon-256x256.png`. Copy them in before committing.

## What changed from the repo copy

* trunk was still at **1.0.2**. It is now the approved **1.0.4** build, taken from the exact zip
  WordPress reviewed.
* `Tested up to:` was **7.0**; now **7.1**. Verified by running the plugin on WordPress 7.1 in
  WordPress Playground — it activates cleanly and the settings screen renders correctly.
* The changelog was missing **1.0.3** and **1.0.4**. Both added.
* Banners and a screenshot did not exist. Both banner sizes are new, drawn to match the
  Controlled Draft Publisher banners (same navy `#244662`, same layout, same glyph treatment).
  `screenshot-1.png` is the real settings screen, captured on WordPress 7.1.

## Commands

```powershell
svn checkout https://plugins.svn.wordpress.org/backburner-post-archiver bb-svn
cd bb-svn

# copy in the three folders from this bundle, then:
svn add trunk/* tags/1.0.4 assets/* --force
svn commit -m "Release 1.0.4: approved build, banners, icons, screenshot"
```

SVN will ask for your **WordPress.org username** (`techygeekshome`, case-sensitive) and your
**SVN password**, which is separate from your wordpress.org account password. Set or retrieve it
at https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password

## After committing

* The public page appears within a few minutes; search results can take up to 72 hours.
* Check the banner and icon render correctly on the plugin page — assets are picked up from
  `assets/`, not from `trunk/`.
* `Stable tag: 1.0.4` in `trunk/readme.txt` is what tells WordPress.org which tag to serve, so
  `tags/1.0.4/` must exist or downloads will 404.
