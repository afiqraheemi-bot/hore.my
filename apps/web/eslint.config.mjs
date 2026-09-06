// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt({
  rules: {
    // Prettier owns formatting (nuxt.config's `stylistic: false` does
    // not reach this rule, since eslint-plugin-vue does not classify
    // it as a stylistic-extension rule) — Prettier always renders a
    // multi-line void element (e.g. `<input>`) self-closed, which this
    // rule would otherwise flag.
    'vue/html-self-closing': 'off',
  },
})
