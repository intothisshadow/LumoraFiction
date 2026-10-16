# Third-party software

Every bundled third-party asset is listed here with its version, source, and licence. Nothing is loaded from an external service at runtime: the editors are stored in `admin/assets/vendor/` and served from the same site.

## TinyMCE

- **Purpose:** the visual (WYSIWYG) editing mode for chapters.
- **Version:** 7.9.1, an exact pin.
- **Added:** 2026-10-16.
- **Licence:** GNU General Public License version 2 or later, used in its self-hosted form with `license_key: 'gpl'`. The licence text is kept at `admin/assets/vendor/tinymce/license.md`.
- **Source:** https://github.com/tinymce/tinymce, taken from the `tinymce@7.9.1` package on npm.
- **Local path:** `admin/assets/vendor/tinymce/`. Only the minified core, the Silver theme, the default icons, the DOM model, the Oxide skin and the `lists`, `link`, `autolink` and `code` plugins are included. No language packs, other plugins, or cloud features are bundled.
- **Notes:** the editor is configured without the upgrade prompts and branding, and loads nothing from tiny.cloud. Alignment is stored as a class rather than an inline style. Its text area uses `css/editor-content.css` instead of an inline style block, so the page's strict Content-Security-Policy is kept.

| File | SHA-256 |
| ---- | ------- |
| `tinymce.min.js` | `a7b838ecffd85c2c0b114c5c06802f1476ccd007b56557c0b2a14b01753c8fe4` |
| `themes/silver/theme.min.js` | `4ac948b6c71e6ed96d636c3600fdf1fa83662b93ed5b15d45f3af9918871de12` |
| `models/dom/model.min.js` | `d6cb35949722f4b836ad383d160b4a77e55ac1e642898af793c2a90bd830dce0` |
| `icons/default/icons.min.js` | `6f835998e904252231194141807f190e97342f34e5d390019c1805fd6f7ccaef` |
| `plugins/autolink/plugin.min.js` | `0c88dbb5b98a8891e671884bd22a2ba227f3d8273520746419c88138ded14d1c` |
| `plugins/code/plugin.min.js` | `aa79fed77edd8b4beae8cefbbb90a25729c0d0fd2b21b56c6c8c59712e9d0cab` |
| `plugins/link/plugin.min.js` | `33e0dc25bdab261cc7e53ad8e1f1d4817d1cdecfb66a17191bc6c2ff55b56666` |
| `plugins/lists/plugin.min.js` | `a6474b2f8566449fed62e537f47942ff46fbc9b7644f7171766a589c366e900c` |
| `skins/ui/oxide/skin.min.css` | `35a0a7c567d7cc412f2ce09994b4add600762930300ad5782a46ccc519bcd40e` |
| `skins/ui/oxide/skin.shadowdom.min.css` | `d8a9f2d384edd57a6e04e52cd58bb3f5c4a566da539b08c1eaeb7f918a42c262` |
| `skins/ui/oxide/content.min.css` | `15901f2ed975b278f065342e7e8c9dc6bbddf664b7b71925290395868682e37a` |
| `skins/content/default/content.min.css` | `8fbc7f18efefe59b8b7d692d25e0c4283aa4100eaba292e1cdda0cd32e20fc88` |

## EasyMDE

- **Purpose:** the Markdown editing mode for chapters.
- **Version:** 2.18.0, an exact pin.
- **Added:** 2026-10-16.
- **Licence:** MIT. The licence text is kept at `admin/assets/vendor/easymde/LICENSE`.
- **Source:** https://github.com/Ionaru/easy-markdown-editor, taken from the `easymde@2.18.0` package on npm.
- **Local path:** `admin/assets/vendor/easymde/` (`easymde.min.js` and `easymde.min.css`). It includes its own copy of CodeMirror 5, which is what draws the text box.
- **Notes:** by default EasyMDE downloads an icon font and spell-checker dictionaries from other sites. Both are switched off in `content-editor.js` (`autoDownloadFontAwesome: false` and `spellChecker: false`), and the toolbar uses text labels instead of icons. A test fails if either option is removed.

| File | SHA-256 |
| ---- | ------- |
| `easymde.min.js` | `42c578c29ae613807f43c292e23365f2f676071450a8f09314668a27720ccee3` |
| `easymde.min.css` | `8a148c947f7e63250d8fb8d97e030b6fef6e02480ea08c0acfacb11618ac11f6` |
