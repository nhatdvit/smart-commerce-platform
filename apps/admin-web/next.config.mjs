/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Standalone output keeps the Docker runtime image small, but Next's file
  // tracing builds it out of symlinks, which Windows refuses without Developer
  // Mode. Only the Docker build needs it, and it sets this variable.
  output: process.env.NEXT_OUTPUT_STANDALONE === 'true' ? 'standalone' : undefined,
  // Workspace packages ship TypeScript source, so Next compiles them.
  transpilePackages: ['@scp/ui', '@scp/api-contracts'],
  experimental: {
    typedRoutes: false,
  },
  async headers() {
    return [
      {
        source: '/(.*)',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'X-Frame-Options', value: 'DENY' },
        ],
      },
    ];
  },
};

export default nextConfig;
