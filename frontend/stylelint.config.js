// Stylelint checks the styles for mistakes and enforces the palette rule; it
// doesn't format them (Prettier does). So it extends the *recommended* configs —
// invalid values, duplicates, deprecated keywords — not stylelint-config-standard,
// whose notation and blank-line rules disagreed with 667 lines of working CSS.

// AUDIT §4.6: a palette colour is defined once, in tokens.css, and used through
// var(--myst-*) (a tint is color-mix(in srgb, var(--myst-*) N%, transparent)).
// Neutral white and black at some alpha — hairlines, hovers, scrims — are not
// palette colours and may be written inline.
const PALETTE_MESSAGE =
  'Use a tokens.css colour (var(--myst-*), color-mix() for a tint); only neutral white/black may be written inline (AUDIT §4.6)'

export default {
  extends: ['stylelint-config-recommended'],
  ignoreFiles: [
    // A trimmed copy of Bulma's helper classes, vendored as-is.
    'src/assets/bulma-helpers.css',
  ],
  rules: {
    'color-no-hex': [true, { message: PALETTE_MESSAGE }],
    'declaration-property-value-disallowed-list': [
      {
        '/.*/': [
          // rgb()/rgba()/hsl()/hsla() unless the channels are pure white or black.
          '/\\b(?:rgba?|hsla?)\\((?!\\s*(?:255[\\s,]+255[\\s,]+255|0[\\s,]+0[\\s,]+0)[\\s,/])/',
        ],
      },
      { message: PALETTE_MESSAGE },
    ],
    // It compares only the last compound selector, so it can't tell
    // `.spread-details-body p` from `.spread-description .content p`: 25 of its 26
    // findings here were unrelated selectors. The one real clash, Bulma's table
    // hover out-ranking ours, is fixed in style.css.
    'no-descending-specificity': null,
  },
  overrides: [
    {
      // The palette itself.
      files: ['src/assets/tokens.css'],
      rules: {
        'color-no-hex': null,
        'declaration-property-value-disallowed-list': null,
      },
    },
    {
      files: ['**/*.scss'],
      extends: ['stylelint-config-recommended-scss'],
      rules: {
        // Blank `//` lines separate the paragraphs of one comment.
        'scss/comment-no-empty': null,
      },
    },
    {
      files: ['**/*.vue'],
      extends: ['stylelint-config-recommended-vue'],
    },
  ],
}
