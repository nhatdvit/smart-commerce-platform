import base from './base.mjs';

/**
 * NestJS API config: decorators and DI constructor parameters are expected,
 * and controllers/providers legitimately use parameter properties.
 */
export default [
  ...base,
  {
    rules: {
      '@typescript-eslint/no-extraneous-class': 'off',
      '@typescript-eslint/parameter-properties': 'off',
      '@typescript-eslint/no-empty-function': ['error', { allow: ['constructors'] }],
    },
  },
  {
    // Architecture boundary: the domain layer must stay framework-free.
    files: ['src/modules/*/domain/**/*.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          paths: [
            { name: '@nestjs/common', message: 'domain/ must not depend on NestJS.' },
            { name: '@nestjs/core', message: 'domain/ must not depend on NestJS.' },
            { name: '@prisma/client', message: 'domain/ must not depend on Prisma.' },
            { name: 'express', message: 'domain/ must not depend on HTTP transport.' },
          ],
          patterns: [
            { group: ['**/infrastructure/**'], message: 'domain/ must not import infrastructure.' },
            { group: ['**/presentation/**'], message: 'domain/ must not import presentation.' },
          ],
        },
      ],
    },
  },
  {
    // Controllers stay thin: no direct persistence access.
    files: ['src/modules/*/presentation/**/*.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@prisma/client',
              message: 'Controllers must not access Prisma directly; use an application service.',
            },
          ],
        },
      ],
    },
  },
];
