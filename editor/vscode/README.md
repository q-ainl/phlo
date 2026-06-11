# Phlo for Visual Studio Code

Syntax highlighting for Phlo (`.phlo`) source files: nodes (`route`, `method`,
`prop`, `view`, `static`, `function`), view HTML with `{{ }}` / `{( )}`
interpolation and `<if>`/`<foreach>` control tags, Phlo CSS in `<style>`
blocks, embedded JavaScript in `<script>` blocks, `%object` shorthands,
`@ metadata` lines and the Phlo constants.

## Install (local)

Symlink or copy this folder into your VS Code extensions directory:

```bash
ln -s /path/to/phlo/editor/vscode ~/.vscode/extensions/q-ainl.phlo-0.1.0
```

Restart VS Code (or run "Developer: Reload Window"). Files with a `.phlo`
extension are highlighted automatically.

## Package (optional)

To build a `.vsix` for distribution:

```bash
cd editor/vscode
npx @vscode/vsce package
```

The grammar is plain TextMate (`syntaxes/phlo.tmLanguage.json`), so it also
works in any editor that consumes TextMate grammars (Sublime Text, JetBrains
IDEs via the TextMate Bundles plugin, Zed, Helix).
