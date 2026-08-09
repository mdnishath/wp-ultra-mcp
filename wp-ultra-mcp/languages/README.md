# Translations

This directory holds `wp-ultra-mcp.pot` (the extracted template) and any
`wp-ultra-mcp-<locale>.mo` / `.po` translation files.

Regenerate the template after changing user-facing strings:

    wp i18n make-pot . languages/wp-ultra-mcp.pot --domain=wp-ultra-mcp

`load_plugin_textdomain('wp-ultra-mcp', ...)` in the main plugin file loads the
matching `.mo` for the active locale on `init`.
