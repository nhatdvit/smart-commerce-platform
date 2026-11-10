import base from '@scp/config/eslint/base.mjs';

/**
 * Root ESLint configuration.
 * Apps and packages extend the shared configs in packages/config/eslint.
 */
export default [
  {
    ignores: [
      '**/node_modules/**',
      '**/dist/**',
      '**/.next/**',
      '**/coverage/**',
      '**/.turbo/**',
      '**/prisma/migrations/**',
    ],
  },
  ...base,
];
