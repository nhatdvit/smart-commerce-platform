import base from './base.mjs';
import globals from 'globals';

/** Next.js app config: browser globals and React/JSX files. */
export default [
  ...base,
  {
    files: ['**/*.ts', '**/*.tsx'],
    languageOptions: {
      globals: { ...globals.browser, ...globals.node },
    },
    rules: {
      'no-console': ['error', { allow: ['warn', 'error'] }],
    },
  },
];
